<?php

namespace ErnestDefoe\Depot\Model;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $file_id
 * @property string $path
 * @property int $width
 * @property int $height
 * @property int $position
 */
class Screenshot extends AbstractModel
{
    protected $table = 'depot_screenshots';

    protected $guarded = [];

    public $timestamps = false;
}
