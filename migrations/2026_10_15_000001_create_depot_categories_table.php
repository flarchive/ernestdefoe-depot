<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Categories, two levels deep: a root ("Minecraft") and its children
 * ("Resource packs"). Deeper than two and a browse page turns into a file
 * manager, which is the thing this extension exists to replace.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('depot_categories')) {
            return;
        }

        $schema->create('depot_categories', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->string('color', 20)->nullable();
            $table->string('icon', 60)->nullable();
            $table->unsignedInteger('position')->default(0);

            // Files that have been approved, cached so a browse page never
            // counts rows. Kept by Counters, not by hand.
            $table->unsignedInteger('file_count')->default(0);

            $table->timestamps();

            $table->index(['parent_id', 'position']);
            $table->foreign('parent_id')->references('id')->on('depot_categories')->nullOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('depot_categories'),
];
