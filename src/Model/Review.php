<?php

namespace ErnestDefoe\Depot\Model;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $file_id
 * @property int $user_id
 * @property int|null $version_id
 * @property int $rating
 * @property string|null $body
 * @property string|null $reply
 * @property \Carbon\Carbon|null $replied_at
 * @property int $helpful_count
 * @property \Carbon\Carbon|null $created_at
 * @property-read User $user
 * @property-read Version|null $version
 * @property-read File $file
 */
class Review extends AbstractModel
{
    protected $table = 'depot_reviews';

    protected $guarded = [];

    // Flarum's AbstractModel turns timestamps off; these tables have them.
    public $timestamps = true;

    protected $casts = ['replied_at' => 'datetime', 'created_at' => 'datetime', 'updated_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Version, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'version_id');
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function helpfulVoters(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'depot_review_helpful', 'review_id', 'user_id');
    }
}
