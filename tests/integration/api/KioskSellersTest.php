<?php

namespace ErnestDefoe\Depot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\File;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Uploaders selling their own files through Kiosk Sellers (Kiosk 1.2+).
 * Member 2 uploaded files 1 and 2; member 3 buys; member 4 uploaded file 3;
 * the forum itself sells file 4 (member 2's upload). User 1 is the admin.
 */
class KioskSellersTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extension('ernestdefoe-kiosk', 'ernestdefoe-depot');
        $this->setting('ernestdefoe-kiosk.sellers', '1');

        $now = Carbon::now();
        $file = fn (int $id, int $user) => ['id' => $id, 'user_id' => $user, 'title' => "Pack $id", 'slug' => "pack-$id", 'source' => 'upload', 'approved_at' => $now, 'latest_version_id' => $id, 'wants_thread' => 0, 'created_at' => $now];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'buyer', 'email' => 'buyer@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_permission' => [['group_id' => 3, 'permission' => 'kiosk.sell']],
            'depot_files' => [$file(1, 2), $file(2, 2), $file(3, 4), $file(4, 2)],
            'depot_versions' => array_map(fn ($id) => ['id' => $id, 'file_id' => $id, 'version' => '1.0', 'url' => "https://example.com/$id.zip", 'created_at' => $now], [1, 2, 3, 4]),
            'kiosk_products' => [['id' => 50, 'name' => 'Forum pack', 'slug' => 'forum-pack', 'created_at' => $now, 'updated_at' => $now]],
            'kiosk_product_unlocks' => [['product_id' => 50, 'type' => 'depot-file', 'target_id' => 4]],
        ]);
    }

    private function list(int $file, int $as = 2): \Psr\Http\Message\ResponseInterface
    {
        return $this->send($this->request('POST', '/api/kiosk/selling/listings', ['authenticatedAs' => $as, 'json' => ['data' => [
            'name' => 'My pack', 'unlocks' => [['type' => 'depot-file', 'targetId' => $file]],
            'prices' => [['kind' => 'one_off', 'amount' => '4.00', 'currency' => 'USD']],
        ]]]));
    }

    private function download(?int $as, int $version = 1): int
    {
        return $this->send($this->request('GET', "/downloads/get/$version", $as ? ['authenticatedAs' => $as] : []))->getStatusCode();
    }

    #[Test]
    public function an_uploader_sells_their_own_file_and_only_buyers_download_it_once_approved(): void
    {
        $response = $this->list(1);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $listing = json_decode((string) $response->getBody(), true)['data']['listings'][0]['id'];

        // Waiting for review: still free.
        $this->assertFalse((bool) File::query()->find(1)->paid);
        $this->assertSame(302, $this->download(4));

        $this->assertSame(200, $this->send($this->request('POST', "/api/kiosk/admin/listings/$listing", ['authenticatedAs' => 1, 'json' => ['data' => ['action' => 'approve']]]))->getStatusCode());
        $this->assertTrue((bool) File::query()->find(1)->paid);
        $this->assertSame(403, $this->download(3));
        $this->assertSame(302, $this->download(2));

        // Bought (an entitlement, as Kiosk's checkout leaves): the buyer downloads.
        $now = Carbon::now();
        $this->database()->table('kiosk_purchases')->insert(['id' => 1, 'number' => 'K-SELLERBUY', 'user_id' => 3, 'product_id' => $listing, 'product_name' => 'My pack', 'amount_cents' => 400,
            'currency' => 'USD', 'kind' => 'one_off', 'provider' => 'paypal', 'status' => 'active', 'seller_id' => 2, 'created_at' => $now, 'updated_at' => $now, 'paid_at' => $now]);
        $this->database()->table('kiosk_entitlements')->insert(['user_id' => 3, 'purchase_id' => 1, 'type' => 'depot-file', 'target_id' => 1, 'starts_at' => $now->copy()->subMinute()]);
        $this->app()->getContainer()->make(\Ernestdefoe\Kiosk\Service\Access::class)->forget(3);
        $this->assertSame(302, $this->download(3));
    }

    #[Test]
    public function only_the_uploaders_own_files_and_not_ones_the_forum_sells(): void
    {
        $this->assertSame(422, $this->list(3)->getStatusCode());
        $this->assertSame(422, $this->list(4)->getStatusCode());
        $this->assertSame(200, $this->list(2)->getStatusCode());
    }

    #[Test]
    public function a_rejected_or_deleted_listing_frees_the_file(): void
    {
        $listing = json_decode((string) $this->list(1)->getBody(), true)['data']['listings'][0]['id'];
        $review = fn (string $action) => $this->send($this->request('POST', "/api/kiosk/admin/listings/$listing", ['authenticatedAs' => 1, 'json' => ['data' => ['action' => $action, 'note' => 'No']]]));

        $review('approve');
        $this->assertTrue((bool) File::query()->find(1)->paid);
        $review('reject');
        $this->assertFalse((bool) File::query()->find(1)->paid);

        $review('approve');
        $this->assertSame(204, $this->send($this->request('DELETE', "/api/kiosk/selling/listings/$listing", ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertFalse((bool) File::query()->find(1)->paid);
        $this->assertSame(302, $this->download(3));
    }
}
