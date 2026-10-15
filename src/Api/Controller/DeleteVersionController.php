<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\Version;
use ErnestDefoe\Depot\Service\Counters;
use ErnestDefoe\Depot\Storage\Store;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class DeleteVersionController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private Store $store, private Counters $counters)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $version = Version::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));
        $file = $this->editableFile($actor, $version->file_id);

        // The last version is the file; delete the file instead.
        if ($file->versions()->count() < 2) {
            $this->fail('version', 'last_version');
        }

        $path = $version->path;
        $version->delete();

        // Bytes are shared by hash: only remove them when nothing else uses them.
        if ($path && ! Version::query()->where('path', $path)->exists()) {
            $this->store->files()->delete($path);
        }

        $this->counters->file($file);

        return new EmptyResponse(204);
    }
}
