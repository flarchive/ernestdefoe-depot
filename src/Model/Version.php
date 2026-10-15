<?php

namespace ErnestDefoe\Depot\Model;

use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $file_id
 * @property string $version
 * @property array|null $changelog
 * @property string|null $path
 * @property string|null $url
 * @property string|null $filename
 * @property string|null $mime
 * @property int $size
 * @property string|null $sha256
 * @property int|null $github_release_id
 * @property int $download_count
 * @property \Carbon\Carbon $created_at
 * @property-read File $file
 */
class Version extends AbstractModel
{
    protected $table = 'depot_versions';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['changelog' => 'array', 'size' => 'int', 'created_at' => 'datetime'];

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    public function isExternal(): bool
    {
        return $this->path === null && $this->url !== null;
    }
}
