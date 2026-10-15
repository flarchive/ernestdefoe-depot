<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('depot_versions')) {
            return;
        }

        $schema->create('depot_versions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('file_id');
            $table->string('version', 40);

            // {added: [], changed: [], fixed: []}
            $table->json('changelog')->nullable();

            // An uploaded file lives on Depot's private disk under the hash of
            // its bytes; a linked one has a url instead. Never both.
            $table->string('path', 200)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('filename', 200)->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->char('sha256', 64)->nullable();

            $table->unsignedBigInteger('github_release_id')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->dateTime('created_at');

            $table->unique(['file_id', 'version']);
            $table->index(['file_id', 'created_at']);
            $table->foreign('file_id')->references('id')->on('depot_files')->cascadeOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('depot_versions'),
];
