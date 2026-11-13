<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/*
 * Group IDS, not names — a name silently grants nothing.
 *
 * Anybody may look and download; members may upload, and their uploads wait
 * for a moderator until the forum decides otherwise.
 */
return Migration::addPermissions([
    'depot.view' => Group::GUEST_ID,
    'depot.download' => Group::GUEST_ID,
    'depot.upload' => Group::MEMBER_ID,
    'depot.review' => Group::MEMBER_ID,
    'depot.skipApproval' => Group::MODERATOR_ID,
    'depot.moderate' => Group::MODERATOR_ID,
]);
