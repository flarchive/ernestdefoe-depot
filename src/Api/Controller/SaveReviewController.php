<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Review;
use ErnestDefoe\Depot\Service\Counters;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Write or change your review of a file. One each; the author cannot review their own. */
class SaveReviewController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Counters $counters)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertRegistered();
        $actor->assertPermission($actor->hasPermission('depot.review'));
        $file = $this->visibleFile($actor, Arr::get($request->getQueryParams(), 'id'));

        if ($file->isOwnedBy($actor) || ! $file->approved_at) {
            $this->fail('rating', 'cannot_review');
        }

        $in = $this->input($request);
        $rating = (int) Arr::get($in, 'rating');
        if ($rating < 1 || $rating > 5) {
            $this->fail('rating', 'bad_rating');
        }

        $review = Review::query()->firstOrNew(['file_id' => $file->id, 'user_id' => $actor->id]);
        $review->rating = $rating;
        $review->body = mb_substr(trim((string) Arr::get($in, 'body', '')), 0, 5000) ?: null;
        $review->version_id = $file->latest_version_id;
        $review->save();

        $this->counters->file($file);

        return new JsonResponse(['id' => $review->id], 200);
    }
}
