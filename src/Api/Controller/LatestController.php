<?php

namespace ErnestDefoe\Depot\Api\Controller;

use ErnestDefoe\Depot\Api\Concerns;
use ErnestDefoe\Depot\Model\File;
use Flarum\Http\UrlGenerator;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The update-check endpoint: what a mod or launcher asks to find out whether
 * there is something newer, without loading a page. Small, stable, and
 * cacheable for a few minutes.
 */
class LatestController implements RequestHandlerInterface
{
    use Concerns;

    public function __construct(private UrlGenerator $url)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = $this->actor($request);
        $file = File::query()->visibleTo($actor)->whereNotNull('approved_at')
            ->where('slug', (string) Arr::get($request->getQueryParams(), 'slug'))->with('latest')->firstOrFail();
        $v = $file->latest;

        return new JsonResponse([
            'file' => $file->slug,
            'version' => $v?->version,
            'released' => $v?->created_at?->toIso8601String(),
            'sha256' => $v?->sha256,
            'size' => $v ? (int) $v->size : null,
            'url' => $v ? $this->url->to('forum')->route('depot.download', ['id' => $v->id]) : null,
            'page' => $this->url->to('forum')->route('depot.file', ['slug' => $file->slug]),
        ], 200, ['Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
    }
}
