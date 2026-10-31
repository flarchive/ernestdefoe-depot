<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        /*
         * One row per file per day. The 30-day trend on every card is read
         * from here in a single query for the whole page.
         */
        if (! $schema->hasTable('depot_download_days')) {
            $schema->create('depot_download_days', function (Blueprint $table) {
                $table->unsignedInteger('file_id');
                $table->date('day');
                $table->unsignedInteger('count')->default(0);
                $table->primary(['file_id', 'day']);
                $table->foreign('file_id')->references('id')->on('depot_files')->cascadeOnDelete();
            });
        }

        /*
         * Who downloaded what recently, so a repeat inside the window is not
         * counted again. The fingerprint is a hash of the member id or the
         * address, never the address itself, and rows older than the window
         * are pruned.
         */
        if (! $schema->hasTable('depot_download_log')) {
            $schema->create('depot_download_log', function (Blueprint $table) {
                $table->unsignedInteger('file_id');
                $table->char('fingerprint', 64);
                $table->dateTime('created_at');
                $table->index(['file_id', 'fingerprint', 'created_at'], 'depot_download_log_seen');
                $table->index('created_at');
                $table->foreign('file_id')->references('id')->on('depot_files')->cascadeOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('depot_download_log');
        $schema->dropIfExists('depot_download_days');
    },
];
