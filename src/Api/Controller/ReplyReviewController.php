<?php

namespace ErnestDefoe\Depot\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Review;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** The file's author answers a review, once, under it. An empty reply removes it. */
class ReplyReviewController implements RequestHandlerInterface
{
    use Concerns;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $review = Review::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $file = $this->visibleFile($actor, $review->file_id);

        if (! $file->isOwnedBy($actor) && ! $actor->hasPermission('depot.moderate')) {
            throw new PermissionDeniedException();
        }

        $reply = mb_substr(trim((string) Arr::get($this->input($request), 'reply', '')), 0, 3000);
        $review->reply = $reply ?: null;
        $review->replied_at = $reply ? Carbon::now() : null;
        $review->save();

        return new JsonResponse(['reply' => $review->reply]);
    }
}
