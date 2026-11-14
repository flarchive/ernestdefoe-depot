<?php

namespace ErnestDefoe\Depot\Products;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\Category;
use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Model\Version;
use ErnestDefoe\Depot\Service\Changelog;
use ErnestDefoe\Depot\Service\Counters;
use ErnestDefoe\Depot\Storage\Store;
use Illuminate\Support\Str;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;

/**
 * How a store puts its products into Depot.
 *
 * A product is an ordinary Depot file whose source is `product`: it appears in
 * browse, search, categories and the spotlight, has versions with changelogs,
 * and reviews — but it has no download. Its page shows the store's prices and
 * a link to buy, and to people who own it, how to install it (ProductAccess).
 *
 * Everything is keyed by the store's own id, so calling put() again updates
 * the same file. Products are approved: the store is the moderator here.
 */
class ProductCatalog
{
    public function __construct(private Counters $counters, private Store $store)
    {
    }

    /**
     * @param array{
     *   title: string, slug?: string, tagline?: string|null, description?: string|null,
     *   category: array{slug: string, name: string, color?: string|null, icon?: string|null},
     *   user_id?: int|null, tiers: array<int, array{name: string, cents: int, currency: string, interval: string|null}>,
     *   buy_url: string, repo?: string|null, licence?: string|null, game?: string|null, compat?: string[],
     *   installs?: int, screenshots?: string[], featured?: bool, icon?: array{name?: string, color?: string, backgroundColor?: string}|null
     * } $data
     */
    public function put(string $key, array $data): File
    {
        $file = File::query()->where('product_key', $key)->first() ?? new File(['product_key' => $key, 'source' => 'product', 'wants_thread' => false]);

        $file->title = mb_substr($data['title'], 0, 120);
        if (! $file->exists) {
            $file->slug = $this->slug($data['slug'] ?? $file->title);
            $file->approved_at = Carbon::now();
            $file->user_id = $data['user_id'] ?? null;
        }
        $file->tagline = isset($data['tagline']) ? mb_substr((string) $data['tagline'], 0, 160) : $file->tagline;
        $file->description = $data['description'] ?? $file->description;
        $file->category_id = $this->category($data['category'])->id;
        $file->price_tiers = array_values(array_map(fn ($t) => [
            'name' => (string) $t['name'],
            'cents' => max(0, (int) $t['cents']),
            'currency' => strtoupper(substr((string) $t['currency'], 0, 3)),
            'interval' => $t['interval'] ?? null,
        ], $data['tiers']));
        $file->buy_url = preg_match('#^https?://#i', $data['buy_url']) ? $data['buy_url'] : null;
        $file->repo = $data['repo'] ?? $file->repo;
        $file->licence = $data['licence'] ?? $file->licence;
        $file->game = $data['game'] ?? $file->game ?? 'Flarum';
        $file->compat = $data['compat'] ?? $file->compat ?? ['2.x'];
        $file->external_downloads = max(0, (int) ($data['installs'] ?? $file->external_downloads));
        if (array_key_exists('icon', $data)) {
            $file->icon = File::cleanIcon($data['icon']);
        }
        $file->save();

        // Screenshots are added once: the store owns them, and re-adding them
        // on every sync would grow the disk forever.
        if (! empty($data['screenshots']) && ! $file->screenshots()->exists()) {
            $position = 0;
            foreach (array_slice($data['screenshots'], 0, 8) as $bytes) {
                try {
                    $upload = new UploadedFile((new StreamFactory())->createStream($bytes), strlen($bytes), UPLOAD_ERR_OK, 'shot.png', 'image/png');
                    $file->screenshots()->create(['position' => ++$position] + $this->store->putScreenshot($upload, 20 * 1048576));
                } catch (\Throwable $e) {
                    // One unreadable picture does not stop the rest.
                }
            }
        }

        $this->counters->file($file);
        $this->counters->categories();

        return $file;
    }

    /** A release of a product, with its notes as written on GitHub. */
    public function release(File $file, string $version, string $notes, Carbon $date): Version
    {
        $row = $file->versions()->firstOrNew(['version' => mb_substr($version, 0, 40)]);
        $row->changelog = Changelog::parse($notes);
        $row->created_at = $date;
        $row->save();
        $this->counters->file($file);

        return $row;
    }

    /** @return string[] versions the file already has */
    public function versions(File $file): array
    {
        return $file->versions()->pluck('version')->all();
    }

    /** Remove products the store no longer sells. @param string[] $keep */
    public function prune(string $prefix, array $keep): int
    {
        $gone = File::query()->where('product_key', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%')->whereNotIn('product_key', $keep)->get();
        foreach ($gone as $file) {
            foreach ($file->screenshots()->pluck('path') as $path) {
                $this->store->deleteScreenshot($path);
            }
            $file->delete();
        }
        if ($gone->isNotEmpty()) {
            $this->counters->categories();
        }

        return $gone->count();
    }

    /** @param array{slug: string, name: string, color?: string|null, icon?: string|null} $c */
    private function category(array $c): Category
    {
        return Category::query()->firstOrCreate(['slug' => Str::slug($c['slug'])], [
            'name' => $c['name'],
            'color' => $c['color'] ?? null,
            'icon' => $c['icon'] ?? null,
            'position' => (int) Category::query()->max('position') + 1,
        ]);
    }

    private function slug(string $title): string
    {
        $base = Str::slug($title) ?: 'file';
        $slug = $base;
        for ($n = 2; File::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "$base-$n";
        }

        return $slug;
    }
}
