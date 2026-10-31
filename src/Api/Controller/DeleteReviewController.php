<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Review;
use ErnestDefoe\Depot\Service\Counters;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteReviewController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Counters $counters)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $review = Review::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $file = $this->visibleFile($actor, $review->file_id);

        if ($review->user_id !== $actor->id && ! $actor->hasPermission('depot.moderate')) {
            throw new PermissionDeniedException();
        }

        $review->delete();
        $this->counters->file($file);

        return new EmptyResponse(204);
    }
}
