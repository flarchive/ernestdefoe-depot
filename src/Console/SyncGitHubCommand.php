<?php

namespace ErnestDefoe\Depot\Console;

use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Notification\NewVersion;
use ErnestDefoe\Depot\Service\GitHub;
use ErnestDefoe\Depot\Service\Packagist;
use Flarum\Console\AbstractCommand;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Hourly: look for new releases on the files linked to GitHub, oldest
 * check first, at most GitHub::PER_RUN repositories a run.
 */
class SyncGitHubCommand extends AbstractCommand
{
    public function __construct(private GitHub $github, private Packagist $packagist, private NotificationSyncer $notifications, private SettingsRepositoryInterface $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('depot:sync-github')->setDescription('Import new GitHub releases as Depot versions, and refresh Packagist install counts');
    }

    protected function fire(): int
    {
        if ($this->settings->get('ernestdefoe-depot.github_sync') === '0') {
            $this->info('GitHub sync is switched off.');

            return 0;
        }

        // GitHub files not yet known to be packages are looked up once a day:
        // composer.json and Packagist, neither of which spends the GitHub quota.
        $unknown = File::query()->where('source', 'github')->whereNull('package')->whereNotNull('approved_at')
            ->where(fn ($q) => $q->whereNull('stats_synced_at')->orWhere('stats_synced_at', '<', \Carbon\Carbon::now()->subDay()))
            ->take(Packagist::PER_RUN)->get();
        foreach ($unknown as $file) {
            $file->package = $this->packagist->detect((string) $file->repo);
            $file->stats_synced_at = $file->package ? null : \Carbon\Carbon::now();
            $file->saveQuietly();
        }

        // Packagist first: it does not spend the GitHub quota, and install
        // counts should stay current even when GitHub is rate-limiting.
        $packages = File::query()->whereNotNull('package')->whereNotNull('approved_at')
            ->orderByRaw('stats_synced_at is not null')->orderBy('stats_synced_at')->take(Packagist::PER_RUN)->get();
        foreach ($packages as $file) {
            $this->packagist->refresh($file);
        }
        $this->info("Packagist: {$packages->count()} packages refreshed.");

        // Files that have never got a version first, so one that missed its
        // first sync (GitHub was rate-limiting) is not left behind the rest.
        $files = File::query()->where('source', 'github')->whereNotNull('approved_at')
            ->orderByRaw('latest_version_id is not null')->orderByRaw('synced_at is not null')->orderBy('synced_at')->take(GitHub::PER_RUN)->get();

        foreach ($files as $file) {
            foreach ($this->github->sync($file) as $version) {
                $this->info("{$file->slug}: {$version->version}");
                $this->notifications->sync(new NewVersion($version), $file->audience()->get()->all());
            }
        }

        return 0;
    }
}
