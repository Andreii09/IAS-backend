<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class News extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'news';

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'category',
        'summary',
        'body',
        'cover_image',
        'tags',
        'status',
        'visibility',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'published_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $news): void {
            if (blank($news->slug) && filled($news->title)) {
                $news->slug = Str::slug((string) $news->title);
            }

            if ($news->status === 'published' && $news->published_at === null) {
                $news->published_at = now();
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(NewsRevision::class, 'news_id')->latest('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where(function (Builder $query): void {
                $query->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeVisibleTo(Builder $query, $user = null): Builder
    {
        $isManager = $user !== null
            && method_exists($user, 'canManageNews')
            && $user->canManageNews();

        if ($isManager) {
            return $query;
        }

        return $query
            ->where('status', 'published')
            ->where(function (Builder $query) use ($user): void {
                $query->where('visibility', 'public');

                if ($user !== null) {
                    $query->orWhere('visibility', 'residents');
                }
            });
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%' . addcslashes($term, '\\%_') . '%';

        return $query->where(function (Builder $query) use ($like): void {
            $query->where('title', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhere('body', 'like', $like);
        });
    }

    public function scopeCategory(Builder $query, ?string $category): Builder
    {
        $category = trim((string) $category);

        return $category === ''
            ? $query
            : $query->where('category', $category);
    }
}
