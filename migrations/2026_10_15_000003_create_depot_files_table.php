<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('depot_files')) {
            return;
        }

        $schema->create('depot_files', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('user_id')->nullable();

            $table->string('title', 120);
            $table->string('slug', 140)->unique();
            $table->string('tagline', 160)->nullable();
            $table->text('description')->nullable();

            // upload | link | github
            $table->string('source', 10)->default('upload');
            $table->string('repo', 140)->nullable();

            $table->string('game', 60)->nullable();
            $table->json('compat')->nullable();
            $table->string('licence', 60)->nullable();

            // Lists the uploader writes one per line: [label, value] pairs,
            // {name, note, optional} requirements, and install steps.
            $table->json('fields')->nullable();
            $table->json('requires')->nullable();
            $table->json('install')->nullable();

            $table->boolean('featured')->default(false);

            // Null until a moderator approves it, or until the uploader's
            // own permission lets it straight through.
            $table->dateTime('approved_at')->nullable();

            // The support discussion: the tag is chosen at upload and the
            // discussion is started when the file goes public.
            $table->boolean('wants_thread')->default(true);
            $table->unsignedInteger('thread_tag_id')->nullable();
            $table->text('thread_opening')->nullable();
            $table->unsignedInteger('discussion_id')->nullable();

            // Cached, never counted on read.
            $table->unsignedInteger('download_count')->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('rating_sum')->default(0);
            $table->unsignedInteger('watcher_count')->default(0);
            $table->unsignedInteger('latest_version_id')->nullable();
            $table->dateTime('released_at')->nullable();
            $table->dateTime('synced_at')->nullable();

            $table->timestamps();

            $table->index(['category_id', 'approved_at', 'released_at'], 'depot_files_browse');
            $table->index(['approved_at', 'download_count'], 'depot_files_popular');
            $table->index(['user_id', 'created_at']);
            $table->index(['source', 'synced_at']);
            $table->index(['featured', 'released_at']);

            $table->foreign('category_id')->references('id')->on('depot_categories')->nullOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('depot_files'),
];
