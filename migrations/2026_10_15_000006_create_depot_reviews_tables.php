<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('depot_reviews')) {
            $schema->create('depot_reviews', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('file_id');
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('version_id')->nullable();
                $table->unsignedTinyInteger('rating');
                $table->text('body')->nullable();
                $table->text('reply')->nullable();
                $table->dateTime('replied_at')->nullable();
                $table->unsignedInteger('helpful_count')->default(0);
                $table->timestamps();

                // One review each. Changing your mind edits it.
                $table->unique(['file_id', 'user_id']);
                $table->index(['file_id', 'rating']);
                $table->foreign('file_id')->references('id')->on('depot_files')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('version_id')->references('id')->on('depot_versions')->nullOnDelete();
            });
        }

        if (! $schema->hasTable('depot_review_helpful')) {
            $schema->create('depot_review_helpful', function (Blueprint $table) {
                $table->unsignedInteger('review_id');
                $table->unsignedInteger('user_id');
                $table->primary(['review_id', 'user_id']);
                $table->foreign('review_id')->references('id')->on('depot_reviews')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            });
        }
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('depot_review_helpful');
        $schema->dropIfExists('depot_reviews');
    },
];
