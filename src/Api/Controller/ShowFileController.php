<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Api\Presenter;
use ErnestDefoe\Depot\Model\Category;
use ErnestDefoe\Depot\Model\File;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ShowFileController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $slug = (string) Arr::get($request->getQueryParams(), 'slug');
        $file = File::query()->visibleTo($actor)->where('slug', $slug)->firstOrFail();

        $related = File::query()->visibleTo($actor)->whereNotNull('approved_at')
            ->where('id', '!=', $file->id)
            ->where(fn ($q) => $q->where('user_id', $file->user_id)->orWhereIn('category_id', optional(Category::tree()->firstWhere('id', $file->category_id))->withChildren() ?: [0]))
            ->orderByDesc('download_count')->take(3)->get();

        return new JsonResponse($this->presenter->full($file, $actor, $related) + [
            'categories' => Category::tree()->map(fn ($c) => $this->presenter->category($c))->values(),
        ]);
    }
}
