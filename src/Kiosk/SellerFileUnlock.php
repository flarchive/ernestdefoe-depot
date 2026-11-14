<?php

namespace ErnestDefoe\Depot\Kiosk;

use ErnestDefoe\Depot\Model\File;
use Ernestdefoe\Kiosk\Unlock\SellableByMembers;
use Flarum\User\User;

/**
 * Files their uploaders may sell themselves, through Kiosk Sellers: an
 * approved upload of their own. Registered instead of FileUnlock only when
 * the installed Kiosk knows about member sellers (see extend.php), so an
 * older Kiosk never meets an interface it doesn't have.
 */
class SellerFileUnlock extends FileUnlock implements SellableByMembers
{
    public function owns(User $seller, int $targetId): bool
    {
        return $seller->exists && File::query()->whereKey($targetId)
            ->where('user_id', $seller->id)->where('source', File::SELLABLE)->whereNotNull('approved_at')->exists()
            // Not a file the forum sells itself: its uploader can't undercut it.
            && ! File::query()->getConnection()->table('kiosk_product_unlocks')
                ->join('kiosk_products', 'kiosk_products.id', '=', 'kiosk_product_unlocks.product_id')
                ->where('kiosk_product_unlocks.type', 'depot-file')->where('kiosk_product_unlocks.target_id', $targetId)
                ->whereNull('kiosk_products.seller_id')->exists();
    }

    public function searchOwn(User $seller, string $query, int $limit): array
    {
        return File::query()
            ->where('user_id', $seller->id)
            ->where('source', File::SELLABLE)
            ->whereNotNull('approved_at')
            ->when($query !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($query, '%_\\').'%'))
            ->orderBy('title')
            ->limit($limit)
            ->get(['id', 'title'])
            ->map(fn (File $f) => ['id' => (int) $f->id, 'label' => (string) $f->title])
            ->all();
    }

    /**
     * Its listing was rejected or deleted: the file goes back to free unless
     * something else still sells it (another approved listing, or the forum).
     */
    public function release(int $targetId): void
    {
        $stillSold = File::query()->getConnection()->table('kiosk_product_unlocks')
            ->join('kiosk_products', 'kiosk_products.id', '=', 'kiosk_product_unlocks.product_id')
            ->where('kiosk_product_unlocks.type', 'depot-file')
            ->where('kiosk_product_unlocks.target_id', $targetId)
            ->where('kiosk_products.is_active', true)
            ->whereNull('kiosk_products.review')
            ->exists();

        if (! $stillSold) {
            File::query()->whereKey($targetId)->update(['paid' => false]);
        }
    }
}
