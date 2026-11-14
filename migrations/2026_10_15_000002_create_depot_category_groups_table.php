<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * A category with rows here is locked to those groups. Anything that can put
 * somebody in a group — a payments extension, a subscription, a moderator —
 * unlocks it, without Depot knowing money exists.
 */
return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('depot_category_groups')) {
            return;
        }

        $schema->create('depot_category_groups', function (Blueprint $table) {
            $table->unsignedInteger('category_id');
            $table->unsignedInteger('group_id');
            $table->primary(['category_id', 'group_id']);
            $table->foreign('category_id')->references('id')->on('depot_categories')->cascadeOnDelete();
            $table->foreign('group_id')->references('id')->on('groups')->cascadeOnDelete();
        });
    },
    'down' => fn (Builder $schema) => $schema->dropIfExists('depot_category_groups'),
];
