<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('depot_screenshots')) {
            return;
        }

        $schema->create('depot_screenshots', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('file_id');
            $table->string('path', 200);
            $table->unsignedSmallInteger('width')->default(0);
            $table->unsignedSmallInteger('height')->default(0);
            $table->unsignedSmallInteger('position')->default(0);

            $table->index(['file_id', 'position']);
            $table->foreign('file_id')->references('id')->on('depot_files')->cascadeOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('depot_screenshots'),
];
