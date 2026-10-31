<?php

namespace ErnestDefoe\Depot\Http;

use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Model\Version;
use ErnestDefoe\Depot\Service\Downloads;
use ErnestDefoe\Depot\Storage\Store;
use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\RedirectResponse;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The only way to a file's bytes.
 *
 * Checks the visitor may see the file and download, counts the download once,
 * then either hands the file to nginx (when an internal location is set),
 * streams it, or — for a linked or GitHub version — redirects to where it
 * lives.
 *
 * Always an attachment and always application/octet-stream, under a sandbox
 * CSP (Flarum adds nosniff to every response): an uploaded .html or .svg must
 * never run on the forum's own origin.
 */
class DownloadController implements RequestHandlerInterface
{
    public function __construct(
        private Store $store,
        private Downloads $downloads,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertPermission($actor->hasPermission('depot.view') && $actor->hasPermission('depot.download'));

        $version = Version::query()->findOrFail((int) Arr::get($request->getQueryParams(), 'id'));

        // A store's product release has nothing to download here.
        if (! $version->path && ! $version->url) {
            return new Response('php://memory', 404);
        }
        File::query()->visibleTo($actor)->findOrFail($version->file_id);

        $ip = (string) ($request->getAttribute('ipAddress') ?? Arr::get($request->getServerParams(), 'REMOTE_ADDR', ''));
        $this->downloads->count($version, $actor, $ip);

        if ($version->isExternal()) {
            return new RedirectResponse($version->url, 302, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        }

        $name = Store::safeName($version->filename ?: 'download');
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', addcslashes($name, '"\\'), rawurlencode($name)),
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-transform',
        ];
        if ($version->sha256) {
            $headers['Digest'] = 'sha-256='.base64_encode(hex2bin($version->sha256));
        }

        // nginx serves the bytes from an `internal` location when one is set:
        // PHP is out of the request in microseconds and range requests work.
        $accel = trim((string) $this->settings->get('ernestdefoe-depot.accel_prefix'));
        if ($accel !== '') {
            return new Response('php://memory', 200, $headers + ['X-Accel-Redirect' => rtrim($accel, '/').'/'.$version->path]);
        }

        $stream = $this->store->files()->readStream($version->path);
        if (! is_resource($stream)) {
            return new Response('php://memory', 404);
        }

        return new Response(new Stream($stream), 200, $headers + ['Content-Length' => (string) $version->size]);
    }
}
