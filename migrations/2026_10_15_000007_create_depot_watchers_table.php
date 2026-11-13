<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('depot_watchers')) {
            return;
        }

        $schema->create('depot_watchers', function (Blueprint $table) {
            $table->unsignedInteger('file_id');
            $table->unsignedInteger('user_id');
            $table->primary(['file_id', 'user_id']);
            $table->index('user_id');
            $table->foreign('file_id')->references('id')->on('depot_files')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('depot_watchers'),
];
