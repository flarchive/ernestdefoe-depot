<?php

namespace ErnestDefoe\Depot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Depot\Extend\Products;
use ErnestDefoe\Depot\Model\File;
use ErnestDefoe\Depot\Products\ProductAccess;
use ErnestDefoe\Depot\Products\ProductCatalog;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/** A store's products: listed with prices, never downloadable, owned per viewer. */
class ProductsTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-depot');
        $this->extend((new Products())->access(OwnsEverythingForUserTwo::class));
        $this->prepareDatabase([User::class => [$this->normalUser(), ['id' => 3, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1]]]);
    }

    private function put(): File
    {
        $catalog = $this->app()->getContainer()->make(ProductCatalog::class);
        $file = $catalog->put('teststore:1', [
            'title' => 'Paid Thing', 'tagline' => 'A thing you buy.', 'category' => ['slug' => 'premium', 'name' => 'Premium'],
            'tiers' => [['name' => 'Annual', 'cents' => 3900, 'currency' => 'usd', 'interval' => 'year'], ['name' => 'Monthly', 'cents' => 400, 'currency' => 'USD', 'interval' => 'month']],
            'buy_url' => 'https://store.example/paid-thing', 'installs' => 12,
        ]);
        $catalog->release($file, '1.2.0', "## Added\n- Something new", Carbon::parse('2026-10-01'));

        return $file;
    }

    #[Test]
    public function a_product_is_listed_with_its_prices_and_installs(): void
    {
        $this->put();
        $card = json_decode((string) $this->send($this->request('GET', '/api/depot/browse'))->getBody(), true)['files'][0];

        $this->assertSame(['Paid Thing', 'product', 12, 'https://store.example/paid-thing'], [$card['title'], $card['source'], $card['downloads'], $card['buyUrl']]);
        $this->assertSame([3900, 'USD'], [$card['tiers'][0]['cents'], $card['tiers'][0]['currency']]);
        $this->assertSame('1.2.0', $card['version']['version']);
    }

    #[Test]
    public function a_product_keeps_its_icon_and_a_hostile_one_is_dropped(): void
    {
        $catalog = $this->app()->getContainer()->make(ProductCatalog::class);
        $base = ['title' => 'Iconic', 'category' => ['slug' => 'premium', 'name' => 'Premium'], 'tiers' => [], 'buy_url' => 'https://store.example/i'];

        $catalog->put('teststore:2', $base + ['icon' => ['name' => 'fas fa-gem', 'color' => '#fff', 'backgroundColor' => '#d97706']]);
        $catalog->put('teststore:3', $base + ['icon' => ['name' => 'fas fa-gem" onmouseover="x', 'color' => 'red;background:url(x)']]);
        $catalog->put('teststore:4', $base + ['icon' => ['name' => 'fas fa-gem', 'color' => 'red;background:url(x)']]);

        $this->assertSame(['name' => 'fas fa-gem', 'color' => '#fff', 'backgroundColor' => '#d97706'], File::query()->where('product_key', 'teststore:2')->first()->icon);
        $this->assertSame([], File::query()->where('product_key', 'teststore:3')->first()->icon);
        $this->assertSame(['name' => 'fas fa-gem', 'color' => null, 'backgroundColor' => null], File::query()->where('product_key', 'teststore:4')->first()->icon);
    }

    #[Test]
    public function putting_it_again_updates_the_same_file(): void
    {
        $this->put();
        $this->put();
        $this->assertSame(1, File::query()->where('product_key', 'teststore:1')->count());
    }

    #[Test]
    public function the_store_says_who_owns_it(): void
    {
        $this->put();
        $owner = json_decode((string) $this->send($this->request('GET', '/api/depot/file', ['authenticatedAs' => 2])->withQueryParams(['slug' => 'paid-thing']))->getBody(), true);
        $other = json_decode((string) $this->send($this->request('GET', '/api/depot/file', ['authenticatedAs' => 3])->withQueryParams(['slug' => 'paid-thing']))->getBody(), true);

        $this->assertTrue($owner['access']['owned']);
        $this->assertSame(['composer require test/paid-thing'], $owner['access']['install']);
        $this->assertFalse($other['access']['owned']);
        $this->assertSame([], $other['access']['install']);
    }

    #[Test]
    public function a_product_has_no_download_and_cannot_be_edited_here(): void
    {
        $file = $this->put();
        $version = $file->versions()->first();

        $this->assertSame(404, $this->send($this->request('GET', "/downloads/get/{$version->id}", ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('POST', "/api/depot/files/{$file->id}", ['authenticatedAs' => 1, 'json' => ['title' => 'Renamed']]))->getStatusCode());
        $this->assertSame(403, $this->send($this->request('DELETE', "/api/depot/files/{$file->id}", ['authenticatedAs' => 1]))->getStatusCode());
    }

    #[Test]
    public function products_the_store_stopped_selling_are_pruned(): void
    {
        $this->put();
        $removed = $this->app()->getContainer()->make(ProductCatalog::class)->prune('teststore:', []);
        $this->assertSame(1, $removed);
        $this->assertSame(0, File::query()->count());
    }
}

class OwnsEverythingForUserTwo implements ProductAccess
{
    public function access(array $files, User $actor): array
    {
        $out = [];
        foreach ($files as $f) {
            $owned = $actor->id === 2;
            $out[$f->product_key] = ['owned' => $owned, 'install' => $owned ? ['composer require test/paid-thing'] : []];
        }

        return $out;
    }
}
