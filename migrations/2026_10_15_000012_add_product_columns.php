<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Files a store extension provides: a paid item listed in Depot, sold and
 * delivered by the store. The key is the store's own id for it, so a re-sync
 * updates the same file instead of adding another.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasColumn('depot_files', 'product_key')) {
            return;
        }
        $schema->table('depot_files', function (Blueprint $table) {
            $table->string('product_key', 100)->nullable()->unique()->after('package');
            $table->json('price_tiers')->nullable()->after('product_key');
            $table->string('buy_url', 500)->nullable()->after('price_tiers');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('depot_files', function (Blueprint $table) {
            $table->dropUnique(['product_key']);
            $table->dropColumn(['product_key', 'price_tiers', 'buy_url']);
        });
    },
];
