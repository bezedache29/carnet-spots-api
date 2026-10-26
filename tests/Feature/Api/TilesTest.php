<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('tiles');
    config(['filesystems.disks.tiles.x_accel_redirect' => null]);
});

describe('index', function () {
    it('lists the pmtiles files with their size, date, checksum and download url, by name', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        Storage::disk('tiles')->put('alsace.pmtiles', 'ALSACE');
        touch(Storage::disk('tiles')->path('alsace.pmtiles'), strtotime('2026-07-14 10:00:00 UTC'));
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/tiles');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'name' => 'alsace',
                'size' => 6,
                'updated_at' => '2026-07-14T10:00:00.000000Z',
                'sha256' => hash('sha256', 'ALSACE'),
                'url' => route('v1.tiles.show', 'alsace'),
            ])
            ->assertJsonPath('data.1.name', 'bretagne');
    });

    it('ignores files that are not pmtiles and sub-folders', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        Storage::disk('tiles')->put('notes.txt', 'notes');
        Storage::disk('tiles')->put('alsace.pmtiles.part', 'ALSACE BEING UPLOADED');
        Storage::disk('tiles')->put('old/alsace.pmtiles', 'ALSACE');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'bretagne');
    });

    it('skips and logs a file the server cannot read instead of failing the whole list', function () {
        Storage::disk('tiles')->put('alsace.pmtiles', 'ALSACE');
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        chmod(Storage::disk('tiles')->path('alsace.pmtiles'), 0000);
        Log::spy();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'bretagne');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['file' => 'alsace.pmtiles']);
    })->skip(fn (): bool => posix_geteuid() === 0, 'root can read any file');

    it('returns an empty list when there is no tile yet', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles')->assertOk()->assertExactJson(['data' => []]);
    });

    it('recomputes the checksum when a file is replaced', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'OLD');
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/tiles')->assertJsonPath('data.0.sha256', hash('sha256', 'OLD'));

        Storage::disk('tiles')->put('bretagne.pmtiles', 'NEW CONTENT');

        $this->getJson('/api/v1/tiles')->assertJsonPath('data.0.sha256', hash('sha256', 'NEW CONTENT'));
    });

    it('returns 401 when not authenticated', function () {
        $this->getJson('/api/v1/tiles')->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->getJson('/api/v1/tiles')->assertForbidden();
    });
});

describe('show', function () {
    it('downloads the file', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->get('/api/v1/tiles/bretagne');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertDownload('bretagne.pmtiles');
        expect(sentContent($response))->toBe('BRETAGNE');
    });

    it('resumes an interrupted download with a Range request', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->get('/api/v1/tiles/bretagne', ['Range' => 'bytes=4-']);

        $response->assertStatus(206)->assertHeader('Content-Range', 'bytes 4-7/8');
        expect(sentContent($response))->toBe('AGNE');
    });

    it('hands the file over to Nginx when X-Accel-Redirect is configured', function () {
        config(['filesystems.disks.tiles.x_accel_redirect' => '/_protected-tiles/']);
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        Sanctum::actingAs(User::factory()->create());

        $response = $this->get('/api/v1/tiles/bretagne');

        $response->assertOk()
            ->assertHeader('X-Accel-Redirect', '/_protected-tiles/bretagne.pmtiles')
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertDownload('bretagne.pmtiles');
        expect($response->getContent())->toBe('');
    });

    it('returns 404 for a file the server cannot read, like the list', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        chmod(Storage::disk('tiles')->path('bretagne.pmtiles'), 0000);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles/bretagne')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    })->skip(fn (): bool => posix_geteuid() === 0, 'root can read any file');

    it('returns the uniform 404 for a missing tile', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles/atlantis')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Not found.']);
    });

    it('does not serve a file outside the tiles folder', function (string $path) {
        Storage::disk('tiles')->put('old/alsace.pmtiles', 'ALSACE');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles/'.$path)->assertNotFound();
    })->with(['old/alsace', '..%2F..%2F.env', '..%2Fprivate%2Fsecret', 'bretagne.pmtiles']);

    it('only accepts letters, digits, dashes and underscores in the name', function (string $name) {
        // These files exist and would be served without the route constraint: Laravel's default one only forbids "/".
        Storage::disk('tiles')->put($name.'.pmtiles', 'X');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tiles/'.rawurlencode($name))->assertNotFound();
    })->with(['.hidden', 'bre tagne', 'bretagne.v2']);

    it('returns 401 when not authenticated', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');

        $this->getJson('/api/v1/tiles/bretagne')->assertUnauthorized();
    });

    it('returns 403 when the email is not verified', function () {
        Storage::disk('tiles')->put('bretagne.pmtiles', 'BRETAGNE');
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->getJson('/api/v1/tiles/bretagne')->assertForbidden();
    });
});
