<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Api\Presenter;
use ErnestDefoe\Depot\Model\Category;
use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Service\Counters;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Category administration: list, create, change, delete and reorder, all
 * admin-only. One class, because the five are a single screen and share
 * every rule about what a category may be.
 */
class CategoriesController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter, private Counters $counters)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');
        $method = $request->getMethod();
        $in = $this->input($request);

        if ($method === 'POST' && str_ends_with($request->getUri()->getPath(), '/order')) {
            $rows = array_values((array) Arr::get($in, 'order', []));
            $parents = array_filter(array_map(fn ($r) => (int) Arr::get($r, 'parent'), $rows));
            foreach ($rows as $position => $row) {
                $id = (int) Arr::get($row, 'id');
                $parent = (int) Arr::get($row, 'parent') ?: null;
                // Two levels: a parent is never itself a child, and nothing is its own parent.
                if ($parent && ($parent === $id || in_array($id, $parents, true) || collect($rows)->contains(fn ($r) => (int) Arr::get($r, 'id') === $parent && Arr::get($r, 'parent')))) {
                    $this->fail('parent', 'bad_parent');
                }
                Category::query()->where('id', $id)->update(['position' => $position, 'parent_id' => $parent]);
            }

            return $this->list();
        }

        if ($method === 'DELETE') {
            $category = Category::query()->findOrFail((int) $id);
            // Files keep living, uncategorised, rather than vanishing with it.
            File::query()->where('category_id', $category->id)->update(['category_id' => $category->parent_id]);
            Category::query()->where('parent_id', $category->id)->update(['parent_id' => null]);
            $category->delete();
            $this->counters->categories();

            return new EmptyResponse(204);
        }

        if ($method === 'POST' || $method === 'PATCH') {
            $category = $id ? Category::query()->findOrFail((int) $id) : new Category(['position' => (int) Category::query()->max('position') + 1]);
            $this->fill($category, $in);
            $category->save();

            if (array_key_exists('groups', $in)) {
                $category->groups()->sync(array_map('intval', (array) $in['groups']));
            }

            return $this->list();
        }

        return $this->list();
    }

    private function list(): JsonResponse
    {
        return new JsonResponse(['categories' => Category::query()->with('groups')->orderBy('position')->get()->map(fn ($c) => $this->presenter->category($c))->values()]);
    }

    private function fill(Category $category, array $in): void
    {
        $name = trim((string) Arr::get($in, 'name', $category->name));
        if ($name === '' || mb_strlen($name) > 80) {
            $this->fail('name', 'bad_category_name');
        }
        $category->name = $name;

        $slug = Str::slug((string) Arr::get($in, 'slug', '') ?: $name) ?: 'category';
        if (Category::query()->where('slug', $slug)->where('id', '!=', $category->id ?? 0)->exists()) {
            $this->fail('slug', 'duplicate_slug', ['slug' => $slug]);
        }
        $category->slug = $slug;

        $category->description = mb_substr(trim((string) Arr::get($in, 'description', $category->description)), 0, 255) ?: null;

        $color = (string) Arr::get($in, 'color', $category->color);
        $category->color = preg_match('/^#[0-9a-f]{3,8}$/i', $color) ? $color : null;

        $icon = (string) Arr::get($in, 'icon', $category->icon);
        $category->icon = preg_match('/^[a-z0-9 -]{1,60}$/i', $icon) ? $icon : null;

        if (array_key_exists('parent', $in)) {
            $parent = $in['parent'] ? Category::query()->find((int) $in['parent']) : null;
            // Two levels: a parent must itself be a root, and never this category.
            if ($parent && ($parent->parent_id || $parent->id === $category->id)) {
                $this->fail('parent', 'bad_parent');
            }
            $category->parent_id = $parent?->id;
        }
    }
}
