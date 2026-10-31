<?php

namespace ErnestDefoe\Depot\Service;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\File;
use GuzzleHttp\Client;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Install counts for files that are Composer packages.
 *
 * A package is never taken on the uploader's word: the name comes from the
 * repository's own composer.json, and Packagist must say that package is
 * published from that same repository. Otherwise any file could show another
 * project's numbers.
 *
 * Neither call uses the GitHub API quota (composer.json is read from
 * raw.githubusercontent.com), every call has a short timeout, and the hourly
 * run is capped.
 */
class Packagist
{
    public const PER_RUN = 100;

    private const NAME = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

    public function __construct(
        private ConnectionInterface $db,
        private Counters $counters,
        private LoggerInterface $log,
    ) {
    }

    /** The package a GitHub repository publishes on Packagist, or null. */
    public function detect(string $repo): ?string
    {
        if (! GitHub::validRepo($repo)) {
            return null;
        }

        try {
            $composer = json_decode((string) $this->client()->get("https://raw.githubusercontent.com/$repo/HEAD/composer.json")->getBody(), true);
            $name = is_array($composer) ? strtolower((string) ($composer['name'] ?? '')) : '';
            if (! preg_match(self::NAME, $name)) {
                return null;
            }

            $package = json_decode((string) $this->client()->get("https://packagist.org/packages/$name.json")->getBody(), true);
            $source = rtrim(preg_replace('#\.git$#', '', (string) ($package['package']['repository'] ?? '')) ?? '', '/');

            return strcasecmp($source, "https://github.com/$repo") === 0 ? $name : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * When the package's first version was published, from Packagist's own
     * metadata. A file's history then starts where the project's did, not on
     * the day it was added to the forum.
     */
    public function adopt(File $file, string $package): void
    {
        $file->package = $package;

        try {
            $versions = $this->versions($package);
            $file->icon = File::cleanIcon($versions[0]['extra']['flarum-extension']['icon'] ?? null);
            $times = array_filter(array_map(fn ($v) => $v['time'] ?? null, $versions));
            if ($times) {
                $first = Carbon::parse(min($times));
                if (! $file->created_at || $first->lt($file->created_at)) {
                    $file->created_at = $first;
                }
            }
        } catch (\Throwable $e) {
            // Without the date the file keeps the day it was added.
        }

        $file->saveQuietly();
        $this->refresh($file);
    }

    /** Read the package's install total and its daily installs for the trend. */
    public function refresh(File $file): void
    {
        if (! $file->package) {
            return;
        }

        $file->stats_synced_at = Carbon::now();
        // Files adopted before icons were read get theirs on the next refresh.
        if ($file->getRawOriginal('icon') === null) {
            try {
                $file->icon = File::cleanIcon($this->versions($file->package)[0]['extra']['flarum-extension']['icon'] ?? null);
            } catch (\Throwable $e) {
                // Tried again next time.
            }
        }
        $file->saveQuietly();

        try {
            $stats = json_decode((string) $this->client()->get("https://packagist.org/packages/{$file->package}/stats.json")->getBody(), true);
            $daily = json_decode((string) $this->client()->get("https://packagist.org/packages/{$file->package}/stats/all.json", [
                'query' => ['average' => 'daily', 'from' => Carbon::today()->subDays(31)->toDateString()],
            ])->getBody(), true);
        } catch (\Throwable $e) {
            $this->log->info("[depot] Packagist stats skipped for {$file->package}: ".$e->getMessage());

            return;
        }

        $file->external_downloads = max(0, (int) ($stats['downloads']['total'] ?? 0));
        $file->saveQuietly();

        $labels = $daily['labels'] ?? [];
        $values = array_values($daily['values'] ?? [])[0] ?? [];
        foreach ($labels as $i => $day) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $day)) {
                continue;
            }
            $this->db->table('depot_download_days')->updateOrInsert(
                ['file_id' => $file->id, 'day' => $day],
                ['count' => max(0, (int) ($values[$i] ?? 0))],
            );
        }

        $this->counters->file($file);
    }

    /**
     * The package's versions from Packagist's static metadata, newest first.
     * p2 files are minified: each version lists only what changed from the one
     * before, so the newest is the only complete entry, which is all we read.
     */
    private function versions(string $package): array
    {
        $meta = json_decode((string) $this->client()->get("https://repo.packagist.org/p2/$package.json")->getBody(), true);

        return array_values($meta['packages'][$package] ?? []);
    }

    private function client(): Client
    {
        return new Client(['timeout' => 6, 'connect_timeout' => 3, 'headers' => ['User-Agent' => 'Depot-for-Flarum']]);
    }
}
