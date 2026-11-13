<?php

namespace ErnestDefoe\Depot\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Notification\FileApproved;
use ErnestDefoe\Depot\Service\Counters;
use ErnestDefoe\Depot\Service\SupportThread;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A moderator lets a file out: it appears, its support discussion starts, and its uploader is told. */
class ApproveFileController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Counters $counters, private SupportThread $thread, private NotificationSyncer $notifications)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $actor->assertPermission($actor->hasPermission('depot.moderate'));
        $file = $this->visibleFile($actor, Arr::get($request->getQueryParams(), 'id'));

        if (! $file->approved_at) {
            $file->approved_at = Carbon::now();
            $file->save();
            $this->counters->categories();
            $this->thread->start($file->fresh(['user']));

            if ($file->user && $file->user_id !== $actor->id) {
                $this->notifications->sync(new FileApproved($file), [$file->user]);
            }
        }

        return new JsonResponse(['approved' => true]);
    }
}
