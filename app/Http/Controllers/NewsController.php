<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreNewsRequest;
use App\Http\Requests\UpdateNewsRequest;
use App\Models\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class NewsController extends Controller
{
    private const CACHE_VERSION_KEY = 'news:feed:version';

    private const CACHE_TTL_SECONDS = 60;

    public function index(Request $request)
    {
        $user = $request->user();

        $key = 'news:feed:' . md5(self::canonicalJson([
            'search' => $request->query('search'),
            'category' => $request->query('category'),
            'page' => $request->query('page', 1),
            'per_page' => $request->query('per_page', 15),
            'role' => $user?->role ?? 'guest',
            'version' => Cache::get(self::CACHE_VERSION_KEY, 0),
        ]));

        return Cache::remember($key, now()->addSeconds(self::CACHE_TTL_SECONDS), function () use ($request) {
            return News::query()
                ->published()
                ->visibleTo($request->user())
                ->when(
                    $request->filled('search'),
                    fn ($query) => $query->search($request->string('search')->toString())
                )
                ->when(
                    $request->filled('category'),
                    fn ($query) => $query->category($request->string('category')->toString())
                )
                ->with('author:id,name,organization')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->paginate(max(1, min(100, $request->integer('per_page', 15))))
                ->appends($request->only(['search', 'category', 'per_page']));
        });
    }

    public function show(News $news): array
    {
        Gate::authorize('view', $news);

        $user = request()->user();
        $data = $news->loadMissing('author:id,name,organization')->toArray();
        $data['can'] = [
            'update' => $user !== null && Gate::forUser($user)->allows('update', $news),
            'delete' => $user !== null && Gate::forUser($user)->allows('delete', $news),
        ];

        return $data;
    }

    public function store(StoreNewsRequest $request): JsonResponse
    {
        $news = News::query()->create(
            $request->passedData() + ['user_id' => $request->user()->getKey()]
        );

        self::bumpCacheVersion();

        return response()->json(
            $news->load('author:id,name,organization'),
            201
        );
    }

    public function update(UpdateNewsRequest $request, News $news): News
    {
        Gate::authorize('update', $news);

        $news->fill($request->passedData());
        $news->save();

        self::bumpCacheVersion();

        return $news->fresh()->load('author:id,name,organization');
    }

    public function destroy(News $news): JsonResponse
    {
        Gate::authorize('delete', $news);

        $news->delete();

        self::bumpCacheVersion();

        return response()->json(['message' => 'News deleted.']);
    }

    /**
     * The route parameter is a raw id: implicit binding would hide trashed rows.
     */
    public function restore(string $news): News
    {
        $news = News::onlyTrashed()->findOrFail($news);

        Gate::authorize('restore', $news);

        $news->restore();

        self::bumpCacheVersion();

        return $news->fresh()->load('author:id,name,organization');
    }

    public function revisions(Request $request, News $news)
    {
        Gate::authorize('view', $news);

        return $news->revisions()
            ->with('user:id,name')
            ->paginate(max(1, min(100, $request->integer('per_page', 15))));
    }

    private static function bumpCacheVersion(): void
    {
        if (Cache::increment(self::CACHE_VERSION_KEY) === false) {
            Cache::forever(self::CACHE_VERSION_KEY, 1);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function canonicalJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }
}
