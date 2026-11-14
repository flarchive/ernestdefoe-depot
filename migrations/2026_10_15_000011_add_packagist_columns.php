<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A GitHub-linked file that is a Composer package is installed with Composer,
 * not downloaded, so its real numbers are Packagist's: the package name, its
 * install total, and when those were last read. A release keeps the address
 * of its notes on GitHub.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasColumn('depot_files', 'package')) {
            $schema->table('depot_files', function (Blueprint $table) {
                $table->string('package', 140)->nullable()->after('repo');
                $table->unsignedInteger('external_downloads')->default(0)->after('download_count');
                $table->dateTime('stats_synced_at')->nullable()->after('synced_at');
                $table->index(['package', 'stats_synced_at']);
            });
        }
        if (! $schema->hasColumn('depot_versions', 'release_url')) {
            $schema->table('depot_versions', function (Blueprint $table) {
                $table->string('release_url', 500)->nullable()->after('url');
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->table('depot_files', function (Blueprint $table) {
            $table->dropIndex(['package', 'stats_synced_at']);
            $table->dropColumn(['package', 'external_downloads', 'stats_synced_at']);
        });
        $schema->table('depot_versions', fn (Blueprint $table) => $table->dropColumn('release_url'));
    },
];
