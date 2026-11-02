<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\File;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A moderator sells an uploaded file through Kiosk, or makes it free again. */
class SellFileController implements RequestHandlerInterface
{
    use Concerns;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertPermission($actor->hasPermission('depot.moderate'));
        $file = $this->visibleFile($actor, Arr::get($request->getQueryParams(), 'id'));
        $paid = filter_var(Arr::get($this->input($request), 'paid'), FILTER_VALIDATE_BOOL);

        if ($paid && ($file->source !== File::SELLABLE || ! File::kioskEnabled())) {
            throw new PermissionDeniedException();
        }

        $file->paid = $paid;
        $file->save();

        return new JsonResponse(['paid' => $file->paid]);
    }
}
