<?php

namespace ErnestDefoe\Depot\Service;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\Version;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Counting a download, once.
 *
 * A repeat by the same member — or, for a guest, the same address — inside
 * the window is not counted again, so a download count means people, not
 * clicks. The address is never stored: only a keyed hash of it, and only for
 * as long as the window lasts.
 */
class Downloads
{
    public function __construct(
        private ConnectionInterface $db,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function count(Version $version, User $actor, string $ip): bool
    {
        $hours = max(1, (int) ($this->settings->get('ernestdefoe-depot.count_window') ?: 24));
        $since = Carbon::now()->subHours($hours);
        $who = $actor->exists ? 'u'.$actor->id : 'a'.$ip;
        $fingerprint = hash_hmac('sha256', $who, (string) $this->settings->get('ernestdefoe-depot.salt', 'depot'));

        $seen = $this->db->table('depot_download_log')
            ->where('file_id', $version->file_id)
            ->where('fingerprint', $fingerprint)
            ->where('created_at', '>=', $since)
            ->exists();

        if ($seen) {
            return false;
        }

        $this->db->transaction(function () use ($version, $fingerprint) {
            $this->db->table('depot_download_log')->insert([
                'file_id' => $version->file_id,
                'fingerprint' => $fingerprint,
                'created_at' => Carbon::now(),
            ]);

            $this->db->table('depot_versions')->where('id', $version->id)->increment('download_count');
            $this->db->table('depot_files')->where('id', $version->file_id)->increment('download_count');

            $today = Carbon::today()->toDateString();
            $updated = $this->db->table('depot_download_days')
                ->where('file_id', $version->file_id)->where('day', $today)
                ->increment('count');

            if (! $updated) {
                $this->db->table('depot_download_days')->insertOrIgnore(['file_id' => $version->file_id, 'day' => $today, 'count' => 1]);
            }
        });

        // Prune now and then rather than on a schedule nobody remembers to run.
        if (random_int(1, 50) === 1) {
            $this->db->table('depot_download_log')->where('created_at', '<', $since)->delete();
            $this->db->table('depot_download_days')->where('day', '<', Carbon::today()->subDays(90)->toDateString())->delete();
        }

        return true;
    }
}
