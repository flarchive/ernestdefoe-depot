<?php

namespace ErnestDefoe\Depot\Http;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Depot's page styles. Built with the extension (css/depot.css), so they
 * always match the installed version; the URL carries the content hash, so a
 * browser keeps each version for a year and fetches the next as soon as it
 * ships. Nothing here reads the request beyond that hash.
 */
class StylesheetController implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = __DIR__.'/../../css/depot.css';
        $hash = substr(hash_file('sha256', $path), 0, 12);
        $current = ($request->getQueryParams()['v'] ?? '') === $hash;

        return new Response(new Stream($path, 'r'), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
            'Cache-Control' => $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300',
            'ETag' => '"'.$hash.'"',
        ]);
    }
}
