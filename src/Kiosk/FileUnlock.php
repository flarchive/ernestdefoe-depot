<?php

namespace ErnestDefoe\Depot\Kiosk;

use ErnestDefoe\Depot\Model\File;
use Ernestdefoe\Kiosk\Unlock\UnlockType;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;

/**
 * Uploaded files sold through Kiosk. Registered only while Kiosk is enabled
 * (see extend.php), so Depot never needs Kiosk installed.
 *
 * Nothing is written when somebody buys: a paid file's download asks Kiosk
 * whether the member holds it (File::canDownload), so access starts and stops
 * exactly with the purchase. Only uploads can be sold: a linked or GitHub
 * file's bytes are public somewhere else.
 */
class FileUnlock implements UnlockType
{
    public function __construct(private UrlGenerator $url)
    {
    }

    public function label(): string
    {
        return 'ernestdefoe-depot.lib.kiosk.file';
    }

    public function icon(): string
    {
        return 'fas fa-download';
    }

    public function search(User $actor, string $query, int $limit): array
    {
        return File::query()
            ->where('source', File::SELLABLE)
            ->whereNotNull('approved_at')
            ->when($query !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('title')
            ->limit($limit)
            ->get(['id', 'title'])
            ->map(fn (File $f) => ['id' => (int) $f->id, 'label' => (string) $f->title])
            ->all();
    }

    public function describe(array $ids): array
    {
        return File::query()->whereIn('id', $ids)->get(['id', 'title', 'slug'])
            ->mapWithKeys(fn (File $f) => [(int) $f->id => [
                'label' => (string) $f->title,
                'url' => $this->url->to('forum')->route('depot.file', ['slug' => $f->slug]),
            ]])
            ->all();
    }

    public function grant(User $user, int $targetId): void
    {
    }

    public function revoke(User $user, int $targetId): void
    {
    }

    /** Selling a file makes its download paid. */
    public function prepare(int $targetId): void
    {
        File::query()->whereKey($targetId)->where('source', File::SELLABLE)->update(['paid' => true]);
    }
}
