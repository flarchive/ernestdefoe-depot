<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Screenshot;
use ErnestDefoe\Depot\Storage\Store;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteScreenshotController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Store $store)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $shot = Screenshot::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $this->editableFile($actor, $shot->file_id);

        $path = $shot->path;
        $shot->delete();
        if (! Screenshot::query()->where('path', $path)->exists()) {
            $this->store->deleteScreenshot($path);
        }

        return new EmptyResponse(204);
    }
}
