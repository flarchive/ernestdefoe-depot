<?php

namespace ErnestDefoe\Depot\Products;

use ErnestDefoe\Depot\Model\File;
use Flarum\User\User;

/**
 * What a store tells Depot about one viewer and its products: whether they
 * already own each one, and how they install it.
 *
 * Asked once per page with every product file on it, so a store answers with
 * one query rather than one per file.
 */
interface ProductAccess
{
    /**
     * @param File[] $files product files, keyed by nothing in particular
     * @return array<string, array{owned: bool, install?: string[], manageUrl?: string|null}> keyed by product_key
     */
    public function access(array $files, User $actor): array;
}
