<?php

namespace ErnestDefoe\Depot\Content;

use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Storage\Store;
use Flarum\Frontend\Document;
use Flarum\User\Guest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A file's page as a link card, built server-side for crawlers that run no
 * JavaScript, and only from what a guest may see.
 *
 * Earlier copies of the tags it writes (an SEO extension's generic guess) are
 * removed first: crawlers take the FIRST occurrence of a tag.
 */
class FileMeta
{
    private const OWNED = ['og:type', 'og:title', 'og:description', 'og:image', 'twitter:card', 'twitter:title', 'twitter:description', 'twitter:image'];

    public function __construct(private Store $store)
    {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $slug = (string) ($request->getQueryParams()['slug'] ?? '');
        $file = File::query()->visibleTo(new Guest())->whereNotNull('approved_at')->with(['screenshots', 'latest'])->where('slug', $slug)->first();
        if (! $file) {
            return;
        }

        $document->title = $file->title;
        $description = trim(preg_replace('~\s+~u', ' ', $file->tagline ?: (string) $file->description) ?? '');
        $description = mb_strlen($description) > 200 ? mb_substr($description, 0, 197).'…' : $description;
        if ($description !== '') {
            $document->meta['description'] = $description;
        }

        $shot = $file->screenshots->first();
        $tags = [
            'og:type' => 'website',
            'og:title' => $file->title.($file->latest ? ' '.$file->latest->version : ''),
            'og:description' => $description,
            'og:image' => $shot ? $this->store->screenshotUrl($shot->path) : null,
            'twitter:card' => $shot ? 'summary_large_image' : 'summary',
            'twitter:title' => $file->title,
            'twitter:description' => $description,
            'twitter:image' => $shot ? $this->store->screenshotUrl($shot->path) : null,
        ];

        $document->head = array_values(array_filter($document->head, function ($entry) {
            if (! is_string($entry)) {
                return true;
            }
            foreach (self::OWNED as $name) {
                if (str_contains($entry, 'property="'.$name.'"') || str_contains($entry, 'name="'.$name.'"')) {
                    return false;
                }
            }

            return true;
        }));

        foreach ($tags as $name => $value) {
            if ($value !== null && $value !== '') {
                $document->head[] = sprintf('<meta %s="%s" content="%s">', str_starts_with($name, 'og:') ? 'property' : 'name', $name, htmlspecialchars($value, ENT_QUOTES, 'UTF-8'));
            }
        }
    }
}
