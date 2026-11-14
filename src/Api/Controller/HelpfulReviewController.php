<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Review;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class HelpfulReviewController implements RequestHandlerInterface
{
    use Concerns;

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $review = Review::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $this->visibleFile($actor, $review->file_id);

        $on = filter_var(Arr::get($this->input($request), 'helpful', true), FILTER_VALIDATE_BOOL) && $review->user_id !== $actor->id;
        $on ? $review->helpfulVoters()->syncWithoutDetaching([$actor->id]) : $review->helpfulVoters()->detach($actor->id);

        $review->helpful_count = $review->helpfulVoters()->count();
        $review->save();

        return new JsonResponse(['helpful' => $review->helpful_count, 'helpfulByMe' => $on]);
    }
}
