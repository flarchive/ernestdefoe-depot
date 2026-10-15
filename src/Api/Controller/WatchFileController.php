<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Service\Counters;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class WatchFileController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Counters $counters)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $file = $this->visibleFile($actor, Arr::get($request->getQueryParams(), 'id'));

        $watch = filter_var(Arr::get($this->input($request), 'watch', true), FILTER_VALIDATE_BOOL);
        $watch ? $file->watchers()->syncWithoutDetaching([$actor->id]) : $file->watchers()->detach($actor->id);
        $this->counters->file($file);

        return new JsonResponse(['watching' => $watch, 'watchers' => $file->watcher_count]);
    }
}
