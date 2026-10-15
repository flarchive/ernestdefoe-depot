<?php

namespace ErnestDefoe\Depot\Api\Controller;

use Carbon\Carbon;
use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Notification\NewVersion;
use ErnestDefoe\Depot\Service\Changelog;
use ErnestDefoe\Depot\Service\Counters;
use ErnestDefoe\Depot\Storage\Store;
use Flarum\Notification\NotificationSyncer;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** A new version of a file: its own bytes (or link), its own changelog, and a notification to everyone watching. */
class SaveVersionController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Store $store, private Counters $counters, private NotificationSyncer $notifications)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $file = $this->editableFile($actor, Arr::get($request->getQueryParams(), 'id'));
        $in = $this->input($request);

        // Versions count against the same hourly limit as files, per file.
        if (! $actor->hasPermission('depot.moderate')) {
            $limit = max(1, (int) (resolve('flarum.settings')->get('ernestdefoe-depot.uploads_per_hour') ?: 10));
            if ($file->versions()->where('created_at', '>', \Carbon\Carbon::now()->subHour())->count() >= $limit) {
                throw new \Flarum\Post\Exception\FloodingException();
            }
        }

        $number = mb_substr(trim((string) Arr::get($in, 'version', '')), 0, 40);
        if ($number === '') {
            $this->fail('version', 'no_version');
        }
        if ($file->versions()->where('version', $number)->exists()) {
            $this->fail('version', 'duplicate_version', ['version' => $number]);
        }

        $attrs = [
            'version' => $number,
            'changelog' => [
                'added' => Changelog::lines(Arr::get($in, 'added')),
                'changed' => Changelog::lines(Arr::get($in, 'changed')),
                'fixed' => Changelog::lines(Arr::get($in, 'fixed')),
            ],
            'created_at' => Carbon::now(),
        ];

        $upload = $request->getUploadedFiles()['file'] ?? null;
        if ($file->source === 'link') {
            $link = trim((string) Arr::get($in, 'link', ''));
            if (! preg_match('#^https://[^\s/$.?\#].[^\s]*$#i', $link)) {
                $this->fail('link', 'bad_link');
            }
            $attrs['url'] = $link;
        } elseif ($upload instanceof UploadedFileInterface) {
            $attrs += $this->store->putFile($upload, $this->maxBytes());
        } else {
            $this->fail('file', 'no_file');
        }

        $version = $file->versions()->create($attrs);
        $this->counters->file($file);

        // Watchers hear about it once the file is public, never before.
        if ($file->approved_at) {
            $this->notifications->sync(new NewVersion($version), $file->audience()->where('users.id', '!=', $actor->id)->get()->all());
        }

        return new JsonResponse(['id' => $version->id], 201);
    }
}
