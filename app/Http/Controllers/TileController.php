<?php

namespace App\Http\Controllers;

use Dedoc\Scramble\Attributes\Endpoint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TileController extends Controller
{
    public const EXTENSION = '.pmtiles';

    #[Endpoint(title: 'List map tiles', description: 'Returns the regional `.pmtiles` map files available for offline use, ordered by name, with their size in bytes, last update and SHA-256 checksum, so the client can check a download is complete before using it.')]
    public function index(): JsonResponse
    {
        $disk = Storage::disk('tiles');

        $tiles = collect($disk->files())
            ->filter(fn (string $file): bool => Str::endsWith($file, self::EXTENSION))
            ->filter(fn (string $file): bool => $this->isReadable($disk, $file))
            ->sort()
            ->map(fn (string $file): array => [
                'name' => Str::beforeLast($file, self::EXTENSION),
                'size' => $disk->size($file),
                'updated_at' => Date::createFromTimestamp($disk->lastModified($file)),
                'sha256' => $this->sha256($disk, $file),
                'url' => route('v1.tiles.show', Str::beforeLast($file, self::EXTENSION)),
            ])
            ->values();

        return response()->json(['data' => $tiles]);
    }

    #[Endpoint(title: 'Download a map tile', description: 'Downloads the regional `.pmtiles` file named `tile`. Supports HTTP range requests, so an interrupted download can be resumed with a `Range` header instead of starting over.')]
    public function show(string $tile): BinaryFileResponse|Response
    {
        $disk = Storage::disk('tiles');
        $file = $tile.self::EXTENSION;

        // Same as the list: a file the server can't read doesn't exist for the client.
        abort_unless($disk->exists($file) && $this->isReadable($disk, $file), 404);

        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$file.'"',
        ];

        // Production: Nginx sends the file (and handles Range requests) so no PHP worker is held for
        // the whole download. Locally (Sail has no Nginx), PHP streams it, Range requests included.
        $xAccelRedirect = config('filesystems.disks.tiles.x_accel_redirect');

        if (filled($xAccelRedirect)) {
            return response('', 200, $headers + ['X-Accel-Redirect' => Str::finish($xAccelRedirect, '/').$file]);
        }

        return response()->download($disk->path($file), $file, $headers);
    }

    /**
     * A file the server can't read (e.g. copied without `chmod 644`) is left out of the list instead of
     * failing it for every region, and logged so the mistake shows up.
     */
    private function isReadable(FilesystemAdapter $disk, string $file): bool
    {
        if (is_readable($disk->path($file))) {
            return true;
        }

        Log::warning('Map tile skipped: the file is not readable by the server.', ['file' => $file]);

        return false;
    }

    /**
     * Hashing a few hundred MB takes a moment, so the checksum is cached per file version: replacing a
     * file changes its size or modification time, and with them the cache key.
     */
    private function sha256(FilesystemAdapter $disk, string $file): string
    {
        return Cache::rememberForever(
            "tiles.sha256.{$file}.{$disk->size($file)}.{$disk->lastModified($file)}",
            fn (): string => hash_file('sha256', $disk->path($file)),
        );
    }
}
