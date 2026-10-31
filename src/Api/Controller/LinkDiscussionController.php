<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Api\Presenter;
use Flarum\Discussion\Discussion;
use Flarum\User\Exception\PermissionDeniedException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Point a file at a discussion that already exists, as its support
 * discussion, or unlink it. Takes the discussion's link or its number.
 *
 * Separate from editing a file so a store's product, which is otherwise
 * edited in the store, can still be given one by a moderator.
 */
class LinkDiscussionController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $file = $this->visibleFile($actor, Arr::get($request->getQueryParams(), 'id'));
        if (! $file->canLinkDiscussion($actor)) {
            throw new PermissionDeniedException();
        }

        $given = trim((string) Arr::get($this->input($request), 'discussion', ''));
        $id = null;

        if ($given !== '') {
            // "139", "/d/139", "https://forum/d/139-some-slug", "https://forum/d/139/4"
            if (! preg_match('#^(?:\d+|.*?/d/(\d+)(?:[-/?\#].*)?)$#', $given, $m)) {
                $this->fail('discussion', 'bad_discussion');
            }
            // Only one the person linking it can see: a link must not reveal,
            // or point people at, a discussion they could not open themselves.
            $discussion = Discussion::query()->whereVisibleTo($actor)->find((int) ($m[1] ?? $given));
            if (! $discussion) {
                $this->fail('discussion', 'bad_discussion');
            }
            $id = $discussion->id;
        }

        $file->discussion_id = $id;
        // Linked or deliberately unlinked: either way Depot must not start one later.
        $file->wants_thread = false;
        $file->save();

        return new JsonResponse(['discussion' => $this->presenter->discussionFor($file, $actor)]);
    }
}
