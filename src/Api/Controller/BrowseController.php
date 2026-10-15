<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Api\Presenter;
use ErnestDefoe\Depot\Model\Category;
use ErnestDefoe\Depot\Model\File;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The browse page: one category or all of them, sorted, filtered, searched.
 *
 * A fixed number of queries per page — the files, then the batch the
 * Presenter loads for them — whatever the page size.
 */
class BrowseController implements RequestHandlerInterface
{
    use Concerns;

    private const PER_PAGE = 24;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $slug = $this->param($request, 'category');
        $sort = $this->param($request, 'sort') ?: 'updated';
        $term = mb_substr(trim($this->param($request, 'q')), 0, 80);
        $compat = mb_substr($this->param($request, 'compat'), 0, 40);
        $mine = $this->param($request, 'filter') === 'mine' && $actor->exists;
        $watching = $this->param($request, 'filter') === 'watching' && $actor->exists;
        $page = max(0, (int) $this->param($request, 'page'));

        $category = $slug !== '' ? Category::tree()->firstWhere('slug', $slug) : null;

        $query = File::query()->visibleTo($actor);

        $queue = $this->param($request, 'filter') === 'queue' && $actor->hasPermission('depot.moderate');

        if ($queue) {
            $query->whereNull('approved_at');
        } elseif ($mine) {
            $query->where('user_id', $actor->id);
        } else {
            // Everybody else's unapproved files stay in the moderation queue,
            // not on the browse page.
            $query->whereNotNull('approved_at');
        }

        if ($watching) {
            $query->whereIn('id', fn ($q) => $q->select('file_id')->from('depot_watchers')->where('user_id', $actor->id));
        }

        if ($slug !== '') {
            $query->whereIn('category_id', $category ? $category->withChildren() : [0]);
        }

        // The stored JSON contains the value exactly as json_encode writes it,
        // quotes included, so a LIKE on that text matches whole entries only.
        // Not whereJsonContains: on SQLite, Laravel prefixes json_each() with
        // the table prefix and every prefixed forum gets a 500.
        if ($compat !== '') {
            $needle = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], json_encode($compat));
            $query->where('compat', 'like', '%'.$needle.'%');
        }

        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
            $query->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('tagline', 'like', $like));
        }

        match ($sort) {
            'downloads' => $query->orderByDesc('download_count'),
            'rating' => $query->orderByRaw('case when rating_count = 0 then 0 else rating_sum * 1.0 / rating_count end desc')->orderByDesc('rating_count'),
            'newest' => $query->orderByDesc('created_at'),
            default => $query->orderByDesc('released_at'),
        };
        $query->orderByDesc('id');

        $files = $query->skip($page * self::PER_PAGE)->take(self::PER_PAGE + 1)->get();
        $more = $files->count() > self::PER_PAGE;
        $files = $files->take(self::PER_PAGE);

        $first = $page === 0 && $term === '' && $compat === '' && ! $mine && ! $watching && ! $queue;

        // The spotlight's files are presented in the same batch as the list.
        $featured = $first && ! $slug
            ? File::query()->visibleTo($actor)->whereNotNull('approved_at')->where('featured', true)->orderByDesc('released_at')->take(5)->get()
            : collect();
        $cards = collect($this->presenter->cards($files->concat($featured->whereNotIn('id', $files->pluck('id')))))->keyBy('id');

        return new JsonResponse([
            'files' => $files->map(fn ($f) => $cards[$f->id])->values(),
            'hasMore' => $more,
            'page' => $page,
            'categories' => Category::tree()->map(fn ($c) => $this->presenter->category($c))->values(),
            'compat' => $this->compat($actor, $category),
            'featured' => $featured->map(fn ($f) => $cards[$f->id])->values(),
            'locked' => $first ? $this->locked($actor, $category) : [],
            'pending' => $actor->hasPermission('depot.moderate') ? File::query()->whereNull('approved_at')->count() : 0,
            'canUpload' => $actor->hasPermission('depot.upload'),
        ]);
    }

    /** The versions files in this view say they work with, newest first, for the filter chips. */
    private function compat(User $actor, ?Category $category): array
    {
        if (! $category) {
            return [];
        }

        $values = File::query()->visibleTo($actor)->whereNotNull('approved_at')
            ->whereIn('category_id', $category->withChildren())
            ->pluck('compat')->flatten()->filter()->unique()->values()->all();

        usort($values, fn ($a, $b) => version_compare((string) $b, (string) $a));

        return array_slice($values, 0, 8);
    }

    /**
     * Locked categories, advertised: a name, a count and who it is for. No
     * file data at all — titles and pictures are what membership buys.
     */
    private function locked(User $actor, ?Category $category): array
    {
        $locked = Category::lockedFor($actor);

        return Category::tree()
            ->filter(fn ($c) => in_array($c->id, $locked, true) && ! $c->parent_id && (! $category || $category->id === $c->id) && $c->file_count > 0)
            ->map(fn ($c) => ['slug' => $c->slug, 'name' => $c->name, 'count' => $c->file_count, 'groups' => $c->lockGroups()->map(fn ($g) => $g->name_plural)->values()])
            ->values()
            ->all();
    }
}
