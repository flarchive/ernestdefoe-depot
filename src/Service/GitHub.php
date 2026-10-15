<?php

namespace ErnestDefoe\Depot\Service;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Model\Version;
use Flarum\Settings\SettingsRepositoryInterface;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

/**
 * Releases from a GitHub repository become versions.
 *
 * The download stays on GitHub: the version records the asset's address and
 * the SHA-256 GitHub publishes for it, so Depot serves no bytes and stores
 * none. Every call has a short timeout and the hourly run is capped, so a
 * slow or rate-limited GitHub costs a skipped repository, never a stuck queue.
 */
class GitHub
{
    public const PER_RUN = 30;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private Counters $counters,
        private LoggerInterface $log,
    ) {
    }

    public static function validRepo(string $repo): bool
    {
        return (bool) preg_match('#^[A-Za-z0-9_.-]{1,39}/[A-Za-z0-9_.-]{1,100}$#', $repo);
    }

    /** @return Version[] the versions added */
    public function sync(File $file, int $limit = 5): array
    {
        if ($file->source !== 'github' || ! $file->repo || ! self::validRepo($file->repo)) {
            return [];
        }

        $file->synced_at = Carbon::now();
        $file->saveQuietly();

        try {
            // With the forum's token, a member could otherwise name a private
            // repository the token can read and publish its releases here.
            if ($this->settings->get('ernestdefoe-depot.github_token')) {
                $repo = json_decode((string) $this->client()->get("repos/{$file->repo}")->getBody(), true);
                if (! is_array($repo) || ($repo['private'] ?? true) !== false) {
                    $this->log->info("[depot] GitHub sync refused for {$file->repo}: not a public repository");

                    return [];
                }
            }

            $response = $this->client()->get("repos/{$file->repo}/releases", ['query' => ['per_page' => $limit]]);
            $releases = json_decode((string) $response->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            $this->log->info("[depot] GitHub sync skipped for {$file->repo}: ".$e->getMessage());

            return [];
        }

        $known = $file->versions()->whereNotNull('github_release_id')->pluck('github_release_id')->all();
        $added = [];

        // Oldest first, so the newest release ends up as the latest version.
        foreach (array_reverse($releases) as $release) {
            if (! empty($release['draft']) || ! empty($release['prerelease']) || in_array($release['id'], $known, false)) {
                continue;
            }

            $asset = $release['assets'][0] ?? null;
            $digest = $asset['digest'] ?? null;
            $version = mb_substr(ltrim((string) ($release['tag_name'] ?? ''), 'vV'), 0, 40);

            if ($version === '' || $file->versions()->where('version', $version)->exists()) {
                continue;
            }

            $added[] = $file->versions()->create([
                'version' => $version,
                'changelog' => Changelog::parse((string) ($release['body'] ?? '')),
                'url' => $asset['browser_download_url'] ?? ($release['zipball_url'] ?? $release['html_url']),
                'filename' => $asset['name'] ?? null,
                'size' => (int) ($asset['size'] ?? 0),
                'sha256' => is_string($digest) && str_starts_with($digest, 'sha256:') ? substr($digest, 7) : null,
                'github_release_id' => $release['id'],
                'created_at' => Carbon::parse($release['published_at'] ?? 'now'),
            ]);
        }

        if ($added) {
            $this->counters->file($file);
        }

        return $added;
    }

    private function client(): Client
    {
        $headers = ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Depot-for-Flarum'];
        if ($token = $this->settings->get('ernestdefoe-depot.github_token')) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return new Client(['base_uri' => 'https://api.github.com/', 'timeout' => 6, 'connect_timeout' => 3, 'headers' => $headers]);
    }
}
