<?php

use Illuminate\Database\Schema\Builder;

/*
 * The key the download log's fingerprints are made with. Random per forum, so
 * a fingerprint cannot be matched against an address list from anywhere else.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        if (! $db->table('settings')->where('key', 'ernestdefoe-depot.salt')->exists()) {
            $db->table('settings')->insert(['key' => 'ernestdefoe-depot.salt', 'value' => bin2hex(random_bytes(32))]);
        }
    },
    'down' => function (Builder $schema) {
        $schema->getConnection()->table('settings')->where('key', 'ernestdefoe-depot.salt')->delete();
    },
];
