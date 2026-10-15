<?php

namespace ErnestDefoe\Depot\Api;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\Category;
use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Model\Review;
use ErnestDefoe\Depot\Model\Screenshot;
use ErnestDefoe\Depot\Model\Version;
use ErnestDefoe\Depot\Storage\Store;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;

/**
 * Every shape the API sends, in one place.
 *
 * Lists are presented as a batch: authors, latest versions, covers and the
 * 30-day trend for a whole page come from a fixed number of queries, however
 * many files are on it. Nothing here lazy-loads a relation per file.
 */
class Presenter
{
    public const TREND_DAYS = 30;

    public function __construct(
        private Store $store,
        private UrlGenerator $url,
        private ConnectionInterface $db,
        private ExtensionManager $extensions,
    ) {
    }

    public function category(Category $c): array
    {
        $groups = $c->lockGroups();

        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'parent' => $c->parent_id ? optional(Category::tree()->firstWhere('id', $c->parent_id))->slug : null,
            'description' => $c->description,
            'color' => $c->color,
            'icon' => $c->icon,
            'position' => $c->position,
            'count' => $c->file_count,
            'locked' => $groups->isNotEmpty(),
            'groups' => $groups->map(fn ($g) => $g->name_plural ?: $g->name_singular)->values(),
            'groupIds' => $groups->pluck('id')->values(),
        ];
    }

    public function user(?User $u): ?array
    {
        if (! $u) {
            return null;
        }

        return [
            'id' => $u->id,
            'username' => $u->username,
            'displayName' => $u->display_name,
            'avatarUrl' => $u->avatar_url,
            'slug' => $u->slug ?? $u->username,
        ];
    }

    /**
     * Cards for a list of files, loaded in one batch.
     *
     * @param Collection<int, File> $files
     */
    public function cards(Collection $files): array
    {
        if ($files->isEmpty()) {
            return [];
        }

        $this->prime($files);
        $ids = $files->pluck('id')->all();
        $covers = $this->covers($ids);
        $trends = $this->trends($ids);

        return $files->map(fn (File $f) => $this->card($f, $covers[$f->id] ?? null, $trends[$f->id] ?? null))->values()->all();
    }

    public function card(File $f, ?Screenshot $cover, ?array $trend): array
    {
        $v = $f->latest;

        return [
            'id' => $f->id,
            'slug' => $f->slug,
            'title' => $f->title,
            'tagline' => $f->tagline,
            'category' => $f->category?->slug,
            'user' => $this->user($f->user),
            'version' => $v ? [
                'id' => $v->id,
                'version' => $v->version,
                'date' => $v->created_at?->toIso8601String(),
                'size' => (int) $v->size,
                'external' => $v->isExternal(),
                'url' => $this->url->to('forum')->route('depot.download', ['id' => $v->id]),
            ] : null,
            'downloads' => $f->download_count,
            'rating' => $f->rating(),
            'ratings' => $f->rating_count,
            'game' => $f->game,
            'compat' => $f->compat ?: [],
            'source' => $f->source,
            'repo' => $f->repo,
            // A Composer package is installed, not downloaded: the page shows
            // the install command and its count is Packagist's.
            'package' => $f->package,
            'host' => $v && $v->url ? parse_url($v->url, PHP_URL_HOST) : null,
            'cover' => $cover ? $this->shot($cover) : null,
            'trend' => $trend ?? array_fill(0, self::TREND_DAYS, 0),
            'featured' => $f->featured,
            'approved' => $f->approved_at !== null,
            'created' => $f->created_at?->toIso8601String(),
        ];
    }

    /**
     * Relations a card needs, loaded once for the whole list: categories come
     * from the tree the request already holds, authors and latest versions in
     * one query each.
     *
     * @param Collection<int, File> $files
     */
    private function prime(Collection $files): void
    {
        $tree = Category::tree()->keyBy('id');
        foreach ($files as $f) {
            if (! $f->relationLoaded('category')) {
                $f->setRelation('category', $tree->get($f->category_id));
            }
        }
        $files->loadMissing(['user', 'latest']);
    }

    /**
     * The whole of one file for its own page, with its related files as cards
     * — all of them presented from one batch.
     *
     * @param Collection<int, File> $related
     */
    public function full(File $f, User $actor, Collection $related): array
    {
        $f->loadMissing(['versions', 'screenshots']);
        // The latest version is one of the versions just loaded.
        $f->setRelation('latest', $f->versions->firstWhere('id', $f->latest_version_id));

        $all = (new Collection([$f]))->concat($related);
        $this->prime($all);
        $ids = $all->pluck('id')->all();
        $covers = $this->covers($ids);
        $trends = $this->trends($ids);

        $reviews = $f->reviews()->with('user')
            ->orderByDesc('helpful_count')->orderByDesc('id')->take(30)->get();
        foreach ($reviews as $r) {
            $r->setRelation('version', $f->versions->firstWhere('id', $r->version_id));
        }

        $helpful = $actor->exists && $reviews->isNotEmpty()
            ? $this->db->table('depot_review_helpful')->where('user_id', $actor->id)->whereIn('review_id', $reviews->pluck('id'))->pluck('review_id')->all()
            : [];

        $mine = $actor->exists ? $f->reviews()->where('user_id', $actor->id)->first() : null;

        $distribution = $this->db->table('depot_reviews')->where('file_id', $f->id)
            ->selectRaw('rating, count(*) as n')->groupBy('rating')->pluck('n', 'rating');

        $author = $f->user ? $this->db->table('depot_files')->where('user_id', $f->user_id)->whereNotNull('approved_at')
            ->selectRaw('count(*) as files, coalesce(sum(download_count), 0) as downloads')->first() : null;

        return $this->card($f, $f->screenshots->first(), $trends[$f->id] ?? null) + [
            'related' => $related->map(fn (File $r) => $this->card($r, $covers[$r->id] ?? null, $trends[$r->id] ?? null))->values()->all(),
            'description' => $f->description,
            'licence' => $f->licence,
            'fields' => $f->fields ?: [],
            'requires' => $f->requires ?: [],
            'install' => $f->install ?: [],
            'watchers' => $f->watcher_count,
            'watching' => $actor->exists && $f->watchers()->where('users.id', $actor->id)->exists(),
            'versions' => $f->versions->map(fn (Version $v) => $this->version($v, $f->repo))->values(),
            'screenshots' => $f->screenshots->map(fn (Screenshot $s) => $this->shot($s))->values(),
            'reviews' => $reviews->map(fn (Review $r) => $this->review($r, in_array($r->id, $helpful, true)))->values(),
            'myReview' => $mine ? $this->review($mine, false) : null,
            'distribution' => array_map(fn ($star) => (int) ($distribution[$star] ?? 0), [5, 4, 3, 2, 1]),
            'discussion' => $this->discussion($f, $actor),
            'author' => $author ? ['files' => (int) $author->files, 'downloads' => (int) $author->downloads] : null,
            'canEdit' => $f->canEdit($actor),
            'canModerate' => $actor->hasPermission('depot.moderate'),
            'canReview' => $actor->exists && ! $f->isOwnedBy($actor) && $actor->hasPermission('depot.review'),
            'canDownload' => $actor->hasPermission('depot.download'),
            'updateUrl' => $this->url->to('api')->route('depot.latest', ['slug' => $f->slug]),
        ];
    }

    public function version(Version $v, ?string $repo = null): array
    {
        return [
            'id' => $v->id,
            'version' => $v->version,
            'date' => $v->created_at?->toIso8601String(),
            'size' => (int) $v->size,
            'downloads' => $v->download_count,
            'sha256' => $v->sha256,
            'filename' => $v->filename,
            'external' => $v->isExternal(),
            'host' => $v->url ? parse_url($v->url, PHP_URL_HOST) : null,
            'changelog' => array_merge(['added' => [], 'changed' => [], 'fixed' => []], $v->changelog ?: []),
            'url' => $this->url->to('forum')->route('depot.download', ['id' => $v->id]),
            'releaseUrl' => $v->release_url ?: ($v->github_release_id && $repo ? "https://github.com/$repo/releases" : null),
        ];
    }

    public function review(Review $r, bool $helpful): array
    {
        return [
            'id' => $r->id,
            'user' => $this->user($r->user),
            'rating' => $r->rating,
            'body' => $r->body,
            'reply' => $r->reply,
            'repliedAt' => $r->replied_at?->toIso8601String(),
            'helpful' => $r->helpful_count,
            'helpfulByMe' => $helpful,
            'version' => $r->version?->version,
            'date' => $r->created_at?->toIso8601String(),
        ];
    }

    public function shot(Screenshot $s): array
    {
        return [
            'id' => $s->id,
            'full' => $this->store->screenshotUrl($s->path),
            'thumb' => $this->store->screenshotUrl($s->path, true),
            'width' => $s->width,
            'height' => $s->height,
        ];
    }

    /** The support discussion, only for people allowed to see it (a restricted tag, a hidden discussion). */
    private function discussion(File $f, User $actor): ?array
    {
        $d = $f->discussion_id ? Discussion::query()->whereVisibleTo($actor)->find($f->discussion_id) : null;
        if (! $d) {
            return null;
        }

        // A primary tag first. Asked only when flarum/tags is on; its tables
        // may exist while the extension is off.
        $tag = null;
        if ($this->extensions->isEnabled('flarum-tags')) {
            $t = $d->belongsToMany(\Flarum\Tags\Tag::class, 'discussion_tag')->orderByRaw('parent_id is not null')->first();
            $tag = $t ? ['name' => $t->name, 'color' => $t->color, 'slug' => $t->slug] : null;
        }

        return [
            'id' => $d->id,
            'title' => $d->title,
            'replies' => max(0, (int) $d->comment_count - 1),
            'url' => $this->url->to('forum')->route('discussion', ['id' => $d->id.($d->slug ? '-'.$d->slug : '')]),
            'tag' => $tag,
        ];
    }

    /**
     * The first screenshot of each file, in one query.
     *
     * @param int[] $ids
     * @return array<int, Screenshot>
     */
    private function covers(array $ids): array
    {
        $out = [];
        foreach (Screenshot::query()->whereIn('file_id', $ids)->orderBy('position')->orderBy('id')->get() as $s) {
            $out[$s->file_id] ??= $s;
        }

        return $out;
    }

    /**
     * Downloads per day for the last 30 days, oldest first, in one query.
     *
     * @param int[] $ids
     * @return array<int, int[]>
     */
    private function trends(array $ids): array
    {
        $start = Carbon::today()->subDays(self::TREND_DAYS - 1);
        $rows = $this->db->table('depot_download_days')
            ->whereIn('file_id', $ids)
            ->where('day', '>=', $start->toDateString())
            ->get(['file_id', 'day', 'count']);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->file_id] ??= array_fill(0, self::TREND_DAYS, 0);
            $i = (int) $start->diffInDays(Carbon::parse($row->day), false);
            if ($i >= 0 && $i < self::TREND_DAYS) {
                $out[$row->file_id][$i] = (int) $row->count;
            }
        }

        return $out;
    }
}
