<?php

namespace ErnestDefoe\Depot\Model;

use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $category_id
 * @property int|null $user_id
 * @property string $title
 * @property string $slug
 * @property string|null $tagline
 * @property string|null $description
 * @property string $source
 * @property string|null $repo
 * @property string|null $package
 * @property string|null $product_key
 * @property array|null $price_tiers
 * @property array|null $icon
 * @property string|null $buy_url
 * @property int $external_downloads
 * @property \Carbon\Carbon|null $stats_synced_at
 * @property string|null $game
 * @property array|null $compat
 * @property string|null $licence
 * @property array|null $fields
 * @property array|null $requires
 * @property array|null $install
 * @property bool $featured
 * @property \Carbon\Carbon|null $approved_at
 * @property bool $wants_thread
 * @property int|null $thread_tag_id
 * @property string|null $thread_opening
 * @property int|null $discussion_id
 * @property int $download_count
 * @property int $rating_count
 * @property int $rating_sum
 * @property int $watcher_count
 * @property int|null $latest_version_id
 * @property \Carbon\Carbon|null $released_at
 * @property \Carbon\Carbon|null $synced_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property-read Category|null $category
 * @property-read User|null $user
 * @property-read Version|null $latest
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Version> $versions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Screenshot> $screenshots
 * @property-read Discussion|null $discussion
 */
class File extends AbstractModel
{
    public const SOURCES = ['upload', 'link', 'github'];

    protected $table = 'depot_files';

    protected $guarded = [];

    // Flarum's AbstractModel turns timestamps off; these tables have them.
    public $timestamps = true;

    protected $casts = [
        'compat' => 'array',
        'price_tiers' => 'array',
        'icon' => 'array',
        'fields' => 'array',
        'requires' => 'array',
        'install' => 'array',
        'featured' => 'bool',
        'wants_thread' => 'bool',
        'approved_at' => 'datetime',
        'released_at' => 'datetime',
        'synced_at' => 'datetime',
        'stats_synced_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<Version, $this> */
    public function latest(): BelongsTo
    {
        return $this->belongsTo(Version::class, 'latest_version_id');
    }

    /** @return HasMany<Version, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(Version::class, 'file_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @return HasMany<Screenshot, $this> */
    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class, 'file_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'file_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'depot_watchers', 'file_id', 'user_id');
    }

    /**
     * Watchers who can still open this file. A category locked after somebody
     * started watching must not keep sending them its titles.
     *
     * @return BelongsToMany<User, $this>
     */
    public function audience(): BelongsToMany
    {
        $query = $this->watchers();
        $category = $this->category_id ? Category::tree()->firstWhere('id', $this->category_id) : null;

        if ($category && $category->isLocked()) {
            $groups = $category->lockGroups()->pluck('id')->all();
            $query->where(fn ($q) => $q->whereHas('groups', fn ($g) => $g->whereIn('groups.id', [...$groups, \Flarum\Group\Group::ADMINISTRATOR_ID, \Flarum\Group\Group::MODERATOR_ID]))->orWhere('users.id', $this->user_id));
        }

        return $query;
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class, 'discussion_id');
    }

    public function rating(): float
    {
        return $this->rating_count ? round($this->rating_sum / $this->rating_count, 1) : 0.0;
    }

    public function isOwnedBy(User $actor): bool
    {
        return $actor->exists && $this->user_id === $actor->id;
    }

    public function canEdit(User $actor): bool
    {
        // A store's product is edited in the store; its next sync would undo
        // anything changed here.
        if ($this->isProduct()) {
            return false;
        }

        return $this->isOwnedBy($actor) || $actor->hasPermission('depot.moderate');
    }

    public function isProduct(): bool
    {
        return $this->source === 'product';
    }

    /**
     * Files this person may open.
     *
     * One scope asked by every read, so no list can forget to ask: approved
     * files outside a locked category, plus the person's own files at any
     * stage, plus everything for a moderator.
     *
     * @param Builder<File> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        if ($actor->hasPermission('depot.moderate')) {
            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            $q->whereNotNull('depot_files.approved_at');
            if ($actor->exists) {
                $q->orWhere('depot_files.user_id', $actor->id);
            }
        });

        $locked = Category::lockedFor($actor);
        if ($locked) {
            $query->where(function (Builder $q) use ($locked, $actor) {
                $q->whereNull('depot_files.category_id')->orWhereNotIn('depot_files.category_id', $locked);
                if ($actor->exists) {
                    $q->orWhere('depot_files.user_id', $actor->id);
                }
            });
        }
    }

    /**
     * Approved files in categories this person cannot open — advertised as a
     * locked card. The weaker claim: a title and a count are advertising; a
     * description, a screenshot and a download are not.
     *
     * @param Builder<File> $query
     */
    public function scopeLockedTeasersFor(Builder $query, User $actor): void
    {
        $query->whereNotNull('depot_files.approved_at')->whereIn('depot_files.category_id', Category::lockedFor($actor) ?: [0]);
    }

    /**
     * A Flarum extension icon (composer.json `extra.flarum-extension.icon`),
     * kept only if every part is plainly what it claims to be: Font Awesome
     * class names and CSS colours, nothing that could carry anything else.
     *
     * @return array{name: string, color: string|null, backgroundColor: string|null}|array{}
     */
    public static function cleanIcon(mixed $icon): array
    {
        $name = is_array($icon) ? trim((string) ($icon['name'] ?? '')) : '';
        // A style, the glyph, then any modifiers (fa-flip-vertical, fa-rotate-90).
        if (! preg_match('/^(fa[srlbd]?|fa-(solid|regular|brands|light|duotone))( fa-[a-z0-9-]+)+$/', $name)) {
            return [];
        }
        $colour = fn ($c) => is_string($c) && preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20})$/i', trim($c)) ? trim($c) : null;

        return ['name' => $name, 'color' => $colour($icon['color'] ?? null), 'backgroundColor' => $colour($icon['backgroundColor'] ?? null)];
    }
}
