<?php

namespace ErnestDefoe\Depot\Model;

use Flarum\Database\AbstractModel;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $color
 * @property string|null $icon
 * @property int $position
 * @property int $file_count
 * @property-read Collection<int, Group> $groups
 */
class Category extends AbstractModel
{
    protected $table = 'depot_categories';

    protected $guarded = [];

    // Flarum's AbstractModel turns timestamps off; these tables have them.
    public $timestamps = true;

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime'];

    /** @var Collection<int, Category>|null every category, loaded once per request */
    private static ?Collection $all = null;

    /** @var array<int, int[]> locked category ids, per actor */
    private static array $locked = [];

    public static function boot()
    {
        parent::boot();

        // Anything that changes the tree invalidates the per-request copy.
        $forget = function () {
            self::$all = null;
            self::$locked = [];
        };
        static::saved($forget);
        static::deleted($forget);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return BelongsToMany<Group, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'depot_category_groups', 'category_id', 'group_id');
    }

    /**
     * The whole tree, in order, with each category's groups — one query for
     * the categories and one for the groups, however many pages ask.
     *
     * @return Collection<int, Category>
     */
    public static function tree(): Collection
    {
        return self::$all ??= self::query()->with('groups')->orderBy('position')->orderBy('name')->get();
    }

    /**
     * The groups that open this category: its own, or its parent's when it has none.
     *
     * @return Collection<int, Group>
     */
    public function lockGroups(): Collection
    {
        if ($this->groups->isNotEmpty() || ! $this->parent_id) {
            return $this->groups;
        }

        $parent = self::tree()->firstWhere('id', $this->parent_id);

        return $parent ? $parent->groups : $this->groups;
    }

    public function isLocked(): bool
    {
        return $this->lockGroups()->isNotEmpty();
    }

    /**
     * Ids of the categories this person may not open.
     *
     * One answer per actor per request: every query that lists files asks
     * it, and categories are a handful of rows, so this is computed in PHP
     * rather than joined into every file query.
     *
     * @return int[]
     */
    public static function lockedFor(User $actor): array
    {
        $key = $actor->id ?? 0;

        if (isset(self::$locked[$key])) {
            return self::$locked[$key];
        }

        if ($actor->hasPermission('depot.moderate')) {
            return self::$locked[$key] = [];
        }

        // Members and guests are in their group implicitly: no group_user row
        // says so, so a category locked to Members has to be told.
        $mine = $actor->exists
            ? [...$actor->groups->pluck('id')->all(), Group::MEMBER_ID]
            : [Group::GUEST_ID];

        return self::$locked[$key] = self::tree()
            ->filter(fn (Category $c) => $c->isLocked() && ! $c->lockGroups()->pluck('id')->intersect($mine)->count())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** This category and its children: what a filter on a root category means. @return int[] */
    public function withChildren(): array
    {
        return self::tree()
            ->filter(fn (Category $c) => $c->id === $this->id || $c->parent_id === $this->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
