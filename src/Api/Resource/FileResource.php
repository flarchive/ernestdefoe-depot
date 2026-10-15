<?php

namespace ErnestDefoe\Depot\Api\Resource;

use ErnestDefoe\Depot\Model\File;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;

/**
 * Exists for notifications only: Flarum asks for the API type of every
 * notification subject, and a model without one 500s /api/notifications for
 * every member of the forum. No endpoints — Depot's own controllers do the
 * reading.
 */
class FileResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'depot-files';
    }

    public function model(): string
    {
        return File::class;
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [Schema\Str::make('title'), Schema\Str::make('slug')];
    }
}
