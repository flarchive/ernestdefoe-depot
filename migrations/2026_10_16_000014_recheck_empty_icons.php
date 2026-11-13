<?php

use Illuminate\Database\Schema\Builder;

/*
 * Ask again for every icon recorded as "none". Two kinds were wrong: icons
 * with a modifier class (fas fa-code-branch fa-flip-vertical) failed the old
 * check, and files not on Packagist were never read from their repository.
 * The next sync looks once more; a file that really has none goes back to [].
 */
return [
    'up' => function (Builder $schema) {
        // Compared in PHP: PostgreSQL has no = for a json column.
        $table = $schema->getConnection()->table('depot_files');
        $ids = $table->clone()->whereNotNull('icon')->pluck('icon', 'id')
            ->filter(fn ($icon) => json_decode((string) $icon, true) === [])
            ->keys();

        if ($ids->isNotEmpty()) {
            $table->clone()->whereIn('id', $ids)->update(['icon' => null]);
        }
    },
    'down' => function (Builder $schema) {
        // Nothing to undo: the next sync writes the answer again.
    },
];
