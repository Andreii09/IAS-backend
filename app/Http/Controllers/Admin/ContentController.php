<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\News;
use App\Models\NewsRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    /**
     * Combined payload used by the React "Content & revisions" screen.
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return response()->json([
            'recent' => NewsRevision::query()
                ->with(['user:id,name', 'news:id,title,slug,status,deleted_at'])
                ->latest('id')
                ->limit(max(1, min(100, $request->integer('per_page', 25))))
                ->get(),
            'deleted' => News::onlyTrashed()
                ->with('author:id,name,email')
                ->orderByDesc('deleted_at')
                ->limit(max(1, min(100, $request->integer('per_page', 25))))
                ->get(),
        ]);
    }

    /**
     * Paginated revision feed; optionally scoped to one story via ?news_id=.
     */
    public function revisions(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return NewsRevision::query()
            ->with(['user:id,name', 'news:id,title,slug,status,deleted_at'])
            ->when($request->filled('news_id') || $request->filled('story_id'), function ($query) use ($request): void {
                $query->where('news_id', $request->input('news_id', $request->input('story_id')));
            })
            ->when($request->filled('user'), fn ($query) => $query->where('user_id', $request->input('user')))
            ->latest('id')
            ->paginate(max(1, min(100, $request->integer('per_page', 25))));
    }

    /**
     * Soft-deleted stories available for restore.
     */
    public function trashed(Request $request)
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Administrator access required.');

        return News::onlyTrashed()
            ->with('author:id,name,email')
            ->orderByDesc('deleted_at')
            ->paginate(max(1, min(100, $request->integer('per_page', 25))));
    }

    public function deleted(Request $request)
    {
        return $this->trashed($request);
    }
}
