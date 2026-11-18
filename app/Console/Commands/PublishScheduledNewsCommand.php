<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\News;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class PublishScheduledNewsCommand extends Command
{
    protected $signature = 'news:publish-scheduled';

    protected $description = 'Publish scheduled news items whose publish time has arrived';

    public function handle(): int
    {
        $published = 0;

        News::query()
            ->where('status', 'scheduled')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->chunkById(100, function ($news) use (&$published): void {
                foreach ($news as $item) {
                    /** @var News $item */
                    $before = $item->getAttributes();

                    $item->status = 'published';
                    $item->save();

                    AuditLogger::record(
                        'news.published.scheduled',
                        $item,
                        ['status' => $before['status'] ?? 'scheduled'],
                        ['status' => 'published']
                    );

                    $published++;
                }
            });

        $this->info(sprintf('Published %d scheduled news item(s).', $published));

        return self::SUCCESS;
    }
}
