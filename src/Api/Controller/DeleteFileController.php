<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Version;
use ErnestDefoe\Depot\Service\Counters;
use ErnestDefoe\Depot\Storage\Store;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Delete a file, its versions, screenshots, reviews and watchers. The
 * support discussion stays: it belongs to the people who wrote in it.
 */
class DeleteFileController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Store $store, private Counters $counters)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $file = $this->editableFile($actor, Arr::get($request->getQueryParams(), 'id'));

        $paths = $file->versions()->whereNotNull('path')->pluck('path')->all();
        $shots = $file->screenshots()->pluck('path')->all();

        $file->delete();

        foreach (array_unique($paths) as $path) {
            if (! Version::query()->where('path', $path)->exists()) {
                $this->store->files()->delete($path);
            }
        }
        foreach ($shots as $shot) {
            $this->store->deleteScreenshot($shot);
        }

        $this->counters->categories();

        return new EmptyResponse(204);
    }
}
