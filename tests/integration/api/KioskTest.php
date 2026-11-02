<?php

namespace ErnestDefoe\Depot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Depot\Kiosk\FileUnlock;
use ErnestDefoe\Depot\Model\File;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Files sold through Kiosk (uwnyuD, Discuss d/40082 #13). User 2 uploaded the
 * file, user 3 bought it, user 4 did not; user 1 is the admin.
 */
class KioskTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-kiosk', 'ernestdefoe-depot');

        $now = Carbon::now();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'buyer', 'email' => 'buyer@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
            'depot_files' => [
                ['id' => 1, 'user_id' => 2, 'title' => 'Pro pack', 'slug' => 'pro-pack', 'source' => 'upload', 'paid' => 1, 'approved_at' => $now, 'latest_version_id' => 1, 'wants_thread' => 0, 'created_at' => $now],
                ['id' => 2, 'user_id' => 2, 'title' => 'Linked pack', 'slug' => 'linked-pack', 'source' => 'link', 'approved_at' => $now, 'latest_version_id' => 2, 'wants_thread' => 0, 'created_at' => $now],
            ],
            'depot_versions' => [
                ['id' => 1, 'file_id' => 1, 'version' => '1.0', 'url' => 'https://example.com/pro.zip', 'created_at' => $now],
                ['id' => 2, 'file_id' => 2, 'version' => '1.0', 'url' => 'https://example.com/linked.zip', 'created_at' => $now],
            ],
            'kiosk_products' => [['id' => 1, 'name' => 'Pro pack', 'slug' => 'pro-pack', 'created_at' => $now, 'updated_at' => $now]],
            'kiosk_purchases' => [['id' => 1, 'number' => 'K-ABCDEFGHIJ', 'user_id' => 3, 'product_id' => 1, 'product_name' => 'Pro pack', 'amount_cents' => 500,
                'currency' => 'USD', 'kind' => 'one_off', 'provider' => 'paypal', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now, 'paid_at' => $now]],
            'kiosk_entitlements' => [['id' => 1, 'user_id' => 3, 'purchase_id' => 1, 'type' => 'depot-file', 'target_id' => 1, 'starts_at' => $now->copy()->subMinute()]],
        ]);
    }

    private function download(?int $as, int $version = 1): int
    {
        return $this->send($this->request('GET', "/downloads/get/$version", $as ? ['authenticatedAs' => $as] : []))->getStatusCode();
    }

    private function page(?int $as): array
    {
        $response = $this->send($this->request('GET', '/api/depot/file', $as ? ['authenticatedAs' => $as] : [])->withQueryParams(['slug' => 'pro-pack']));

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    #[Test]
    public function a_paid_file_downloads_only_for_its_buyer_uploader_and_moderators(): void
    {
        $this->assertSame(302, $this->download(3));
        $this->assertSame(302, $this->download(2));
        $this->assertSame(302, $this->download(1));
        $this->assertSame(403, $this->download(4));
        $this->assertSame(403, $this->download(null));

        // Nobody who was turned away is counted as a download.
        $this->assertSame(3, (int) $this->database()->table('depot_versions')->where('id', 1)->value('download_count'));
    }

    #[Test]
    public function the_page_stays_open_and_says_who_may_download(): void
    {
        $other = $this->page(4);
        $this->assertSame([true, false, false], [$other['paid'], $other['canDownload'], $other['canSell']]);
        $this->assertTrue($this->page(3)['canDownload']);
        $this->assertSame([true, true], [$this->page(1)['canDownload'], $this->page(1)['canSell']]);
        $this->assertFalse($this->page(null)['canDownload']);
    }

    #[Test]
    public function an_ended_purchase_closes_the_download(): void
    {
        $this->database()->table('kiosk_entitlements')->where('id', 1)->update(['ends_at' => Carbon::now()->subMinute()]);

        $this->assertSame(403, $this->download(3));
    }

    #[Test]
    public function only_moderators_sell_and_only_uploads_can_be_sold(): void
    {
        $sell = fn (int $as, int $file, bool $paid) => $this->send($this->request('POST', "/api/depot/files/$file/sell", ['authenticatedAs' => $as, 'json' => ['paid' => $paid]]))->getStatusCode();

        $this->assertSame(403, $sell(2, 1, false));
        $this->assertSame(403, $sell(1, 2, true));
        $this->assertFalse((bool) File::query()->find(2)->paid);

        $this->assertSame(200, $sell(1, 1, false));
        $this->assertFalse((bool) File::query()->find(1)->paid);
        $this->assertSame(302, $this->download(4));
    }

    #[Test]
    public function kiosk_finds_uploads_only_and_selling_one_makes_it_paid(): void
    {
        $unlock = $this->app()->getContainer()->make(FileUnlock::class);
        File::query()->whereKey(1)->update(['paid' => false]);

        $this->assertSame([['id' => 1, 'label' => 'Pro pack']], $unlock->search(User::query()->find(1), 'pack', 10));
        $this->assertSame('Pro pack', $unlock->describe([1, 99])[1]['label']);

        $unlock->prepare(1);
        $unlock->prepare(2);
        $this->assertSame([true, false], [(bool) File::query()->find(1)->paid, (bool) File::query()->find(2)->paid]);
    }

    #[Test]
    public function the_store_offers_the_product_that_sells_a_file(): void
    {
        $this->database()->table('kiosk_product_unlocks')->insert(['product_id' => 1, 'type' => 'depot-file', 'target_id' => 1]);
        $this->database()->table('kiosk_prices')->insert(['product_id' => 1, 'amount_cents' => 500, 'currency' => 'USD', 'kind' => 'one_off', 'created_at' => Carbon::now()]);
        $response = $this->send($this->request('GET', '/api/kiosk/store', ['authenticatedAs' => 4])->withQueryParams(['type' => 'depot-file', 'id' => '1']));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('Pro pack', (string) $response->getBody());
    }
}
