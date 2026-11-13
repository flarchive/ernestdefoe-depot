<?php

namespace ErnestDefoe\Depot\Service;

use ErnestDefoe\Depot\Model\Category;
use ErnestDefoe\Depot\Model\File;
use Illuminate\Database\ConnectionInterface;

/**
 * The cached numbers, recomputed from the rows rather than nudged up and down.
 * A counter that is only ever incremented drifts the first time a delete
 * fails half way; one that is recounted cannot.
 */
class Counters
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function file(File $file): void
    {
        $latest = $file->versions()->first();
        $ratings = $this->db->table('depot_reviews')->where('file_id', $file->id)
            ->selectRaw('count(*) as n, coalesce(sum(rating), 0) as total')->first();

        $file->latest_version_id = $latest?->id;
        $file->released_at = $latest?->created_at;
        $file->rating_count = (int) $ratings->n;
        $file->rating_sum = (int) $ratings->total;
        $file->watcher_count = $this->db->table('depot_watchers')->where('file_id', $file->id)->count();
        // Downloads Depot served, plus a Composer package's installs on Packagist.
        $file->download_count = (int) $this->db->table('depot_versions')->where('file_id', $file->id)->sum('download_count') + (int) $file->external_downloads;
        $file->save();
    }

    public function categories(): void
    {
        // One grouped count for the whole tree; only a changed number is written.
        $counts = File::query()->whereNotNull('approved_at')->whereNotNull('category_id')
            ->groupBy('category_id')->selectRaw('category_id, count(*) as n')->pluck('n', 'category_id');

        foreach (Category::query()->get() as $category) {
            $category->file_count = (int) ($counts[$category->id] ?? 0);
            $category->saveQuietly();
        }
    }
}
