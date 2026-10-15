<?php

namespace ErnestDefoe\Depot\Console;

use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Notification\NewVersion;
use ErnestDefoe\Depot\Service\GitHub;
use Flarum\Console\AbstractCommand;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Hourly: look for new releases on the files linked to GitHub, oldest
 * check first, at most GitHub::PER_RUN repositories a run.
 */
class SyncGitHubCommand extends AbstractCommand
{
    public function __construct(private GitHub $github, private NotificationSyncer $notifications, private SettingsRepositoryInterface $settings)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('depot:sync-github')->setDescription('Import new GitHub releases as Depot versions');
    }

    protected function fire(): int
    {
        if ($this->settings->get('ernestdefoe-depot.github_sync') === '0') {
            $this->info('GitHub sync is switched off.');

            return 0;
        }

        $files = File::query()->where('source', 'github')->whereNotNull('approved_at')
            ->orderByRaw('synced_at is not null')->orderBy('synced_at')->take(GitHub::PER_RUN)->get();

        foreach ($files as $file) {
            foreach ($this->github->sync($file) as $version) {
                $this->info("{$file->slug}: {$version->version}");
                $this->notifications->sync(new NewVersion($version), $file->audience()->get()->all());
            }
        }

        return 0;
    }
}
