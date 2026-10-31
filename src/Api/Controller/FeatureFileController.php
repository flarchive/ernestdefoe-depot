<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FeatureFileController implements RequestHandlerInterface
{
    use Concerns;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertPermission($actor->hasPermission('depot.moderate'));
        $file = $this->visibleFile($actor, Arr::get($request->getQueryParams(), 'id'));

        $file->featured = ! $file->featured;
        $file->save();

        return new JsonResponse(['featured' => $file->featured]);
    }
}
