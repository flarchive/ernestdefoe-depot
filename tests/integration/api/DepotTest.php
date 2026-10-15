<?php

namespace ErnestDefoe\Depot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Depot\Service\Changelog;
use ErnestDefoe\Depot\Service\GitHub;
use ErnestDefoe\Depot\Storage\Store;
use Flarum\Foundation\ValidationException;
use Flarum\Group\Group;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rules a guest, a member, an owner and a moderator meet. Runs without
 * network access: no file here is linked to GitHub.
 */
class DepotTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-depot');

        $now = Carbon::now();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'supporter', 'email' => 'supporter@machine.local', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'other', 'email' => 'other@machine.local', 'is_email_confirmed' => 1],
            ],
            Tag::class => [['id' => 1, 'name' => 'Support', 'slug' => 'support', 'position' => 0, 'discussion_count' => 0]],
            Group::class => [['id' => 5, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters']],
            'group_user' => [['user_id' => 3, 'group_id' => 5]],
            'depot_categories' => [
                ['id' => 1, 'name' => 'Maps', 'slug' => 'maps', 'position' => 0, 'file_count' => 1],
                ['id' => 2, 'name' => 'Vault', 'slug' => 'vault', 'position' => 1, 'file_count' => 1],
            ],
            'depot_category_groups' => [['category_id' => 2, 'group_id' => 5]],
            'depot_files' => [
                ['id' => 1, 'category_id' => 1, 'user_id' => 2, 'title' => 'Public map', 'slug' => 'public-map', 'source' => 'link', 'approved_at' => $now, 'latest_version_id' => 1, 'wants_thread' => 0, 'created_at' => $now],
                ['id' => 2, 'category_id' => 1, 'user_id' => 2, 'title' => 'Pending map', 'slug' => 'pending-map', 'source' => 'link', 'approved_at' => null, 'wants_thread' => 0, 'created_at' => $now],
                ['id' => 3, 'category_id' => 2, 'user_id' => 1, 'title' => 'Secret pack', 'slug' => 'secret-pack', 'source' => 'link', 'approved_at' => $now, 'latest_version_id' => 3, 'wants_thread' => 0, 'created_at' => $now],
            ],
            'depot_versions' => [
                ['id' => 1, 'file_id' => 1, 'version' => '1.0', 'url' => 'https://example.com/map.zip', 'created_at' => $now],
                ['id' => 2, 'file_id' => 2, 'version' => '1.0', 'url' => 'https://example.com/pending.zip', 'created_at' => $now],
                ['id' => 3, 'file_id' => 3, 'version' => '1.0', 'url' => 'https://example.com/secret.zip', 'created_at' => $now],
            ],
        ]);
    }

    private function json($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function browse(?int $as = null, array $query = []): array
    {
        $response = $this->send($this->request('GET', '/api/depot/browse', $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query));
        $this->assertSame(200, $response->getStatusCode());

        return $this->json($response);
    }

    #[Test]
    public function guests_see_approved_files_only(): void
    {
        $titles = array_column($this->browse()['files'], 'title');

        $this->assertSame(['Public map'], $titles);
    }

    #[Test]
    public function a_locked_category_is_advertised_without_file_data(): void
    {
        $data = $this->browse();

        $this->assertCount(1, $data['locked']);
        $this->assertSame(['slug' => 'vault', 'name' => 'Vault', 'count' => 1, 'groups' => ['Supporters']], $data['locked'][0]);
        $this->assertStringNotContainsString('Secret pack', json_encode($data));
    }

    #[Test]
    public function a_group_member_opens_the_locked_category(): void
    {
        $titles = array_column($this->browse(3, ['category' => 'vault'])['files'], 'title');

        $this->assertSame(['Secret pack'], $titles);
    }

    #[Test]
    public function files_filter_by_what_they_work_with(): void
    {
        $this->database()->table('depot_files')->where('id', 1)->update(['compat' => json_encode(['1.21.4', '1.21'])]);

        $this->assertSame(['Public map'], array_column($this->browse(null, ['category' => 'maps', 'compat' => '1.21.4'])['files'], 'title'));
        $this->assertSame([], $this->browse(null, ['category' => 'maps', 'compat' => '1.20'])['files']);
        $this->assertSame(['1.21.4', '1.21'], $this->browse(null, ['category' => 'maps'])['compat']);
    }

    #[Test]
    public function a_pending_file_is_a_404_to_everyone_but_its_owner_and_moderators(): void
    {
        $guest = $this->send($this->request('GET', '/api/depot/file')->withQueryParams(['slug' => 'pending-map']));
        $other = $this->send($this->request('GET', '/api/depot/file', ['authenticatedAs' => 4])->withQueryParams(['slug' => 'pending-map']));
        $owner = $this->send($this->request('GET', '/api/depot/file', ['authenticatedAs' => 2])->withQueryParams(['slug' => 'pending-map']));
        $admin = $this->send($this->request('GET', '/api/depot/file', ['authenticatedAs' => 1])->withQueryParams(['slug' => 'pending-map']));

        $this->assertSame(404, $guest->getStatusCode());
        $this->assertSame(404, $other->getStatusCode());
        $this->assertSame(200, $owner->getStatusCode());
        $this->assertSame(200, $admin->getStatusCode());
    }

    #[Test]
    public function the_queue_is_for_moderators(): void
    {
        // For anyone else the filter is ignored: they get the public list, without the pending file.
        $this->assertNotContains('Pending map', array_column($this->browse(4, ['filter' => 'queue'])['files'], 'title'));
        $this->assertSame(['Pending map'], array_column($this->browse(1, ['filter' => 'queue'])['files'], 'title'));
    }

    #[Test]
    public function a_member_cannot_edit_or_delete_anothers_file(): void
    {
        $edit = $this->send($this->request('POST', '/api/depot/files/1', ['authenticatedAs' => 4, 'json' => ['title' => 'Mine now']]));
        $delete = $this->send($this->request('DELETE', '/api/depot/files/1', ['authenticatedAs' => 4]));

        $this->assertSame(403, $edit->getStatusCode());
        $this->assertSame(403, $delete->getStatusCode());
    }

    #[Test]
    public function a_member_uploads_a_link_and_it_waits_for_approval(): void
    {
        $response = $this->send($this->request('POST', '/api/depot/files', [
            'authenticatedAs' => 2,
            'json' => ['source' => 'link', 'title' => 'New map', 'category' => 'maps', 'version' => '2.0', 'link' => 'https://example.com/new.zip', 'thread' => '0'],
        ]));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertFalse($this->json($response)['approved']);
    }

    #[Test]
    public function uploads_are_validated(): void
    {
        $send = fn (array $body) => $this->send($this->request('POST', '/api/depot/files', ['authenticatedAs' => 2, 'json' => $body + ['thread' => '0']]))->getStatusCode();

        $this->assertSame(422, $send(['source' => 'link', 'title' => 'x', 'category' => 'maps', 'link' => 'javascript:alert(1)']), 'a non-https link');
        $this->assertSame(422, $send(['source' => 'link', 'title' => 'x', 'category' => 'vault', 'link' => 'https://example.com/a']), 'a locked category');
        $this->assertSame(422, $send(['source' => 'github', 'title' => 'x', 'category' => 'maps', 'repo' => '../../etc']), 'a bad repository');
        $this->assertSame(422, $send(['source' => 'link', 'title' => '', 'category' => 'maps', 'link' => 'https://example.com/a']), 'no title');
    }

    #[Test]
    public function uploads_are_limited_per_hour(): void
    {
        $this->setting('ernestdefoe-depot.uploads_per_hour', '2');
        $send = fn ($i) => $this->send($this->request('POST', '/api/depot/files', [
            'authenticatedAs' => 4,
            'json' => ['source' => 'link', 'title' => "Flood $i", 'category' => 'maps', 'link' => 'https://example.com/a.zip', 'thread' => '0'],
        ]))->getStatusCode();

        $this->assertSame([201, 201, 429], [$send(1), $send(2), $send(3)]);
    }

    #[Test]
    public function a_linked_download_redirects_and_counts_once(): void
    {
        $first = $this->send($this->request('GET', '/downloads/get/1', ['authenticatedAs' => 4]));
        $again = $this->send($this->request('GET', '/downloads/get/1', ['authenticatedAs' => 4]));

        $this->assertSame(302, $first->getStatusCode());
        $this->assertSame('https://example.com/map.zip', $first->getHeaderLine('Location'));
        $this->assertSame(302, $again->getStatusCode());
        $this->assertSame(1, (int) $this->database()->table('depot_files')->where('id', 1)->value('download_count'));
    }

    #[Test]
    public function a_locked_files_download_is_refused(): void
    {
        $this->assertSame(404, $this->send($this->request('GET', '/downloads/get/3', ['authenticatedAs' => 4]))->getStatusCode());
        $this->assertSame(302, $this->send($this->request('GET', '/downloads/get/3', ['authenticatedAs' => 3]))->getStatusCode());
    }

    #[Test]
    public function reviews_follow_the_rules(): void
    {
        $review = fn (int $as, int $file, int $rating) => $this->send($this->request('POST', "/api/depot/files/$file/review", ['authenticatedAs' => $as, 'json' => ['rating' => $rating, 'body' => 'Good.']]))->getStatusCode();

        $this->assertSame(422, $review(2, 1, 5), 'the author reviewing their own file');
        $this->assertSame(422, $review(4, 1, 9), 'a rating out of range');
        $this->assertSame(200, $review(4, 1, 4));
        $this->assertSame(200, $review(4, 1, 5), 'changing your mind edits the review');

        $file = $this->database()->table('depot_files')->where('id', 1)->first();
        $this->assertSame([1, 5], [(int) $file->rating_count, (int) $file->rating_sum]);
    }

    #[Test]
    public function approval_starts_the_support_discussion_in_the_chosen_tag(): void
    {
        $this->database()->table('depot_files')->where('id', 2)->update(['wants_thread' => 1, 'thread_tag_id' => 1]);

        $response = $this->send($this->request('POST', '/api/depot/files/2/approve', ['authenticatedAs' => 1]));
        $this->assertSame(200, $response->getStatusCode());

        $discussion = (int) $this->database()->table('depot_files')->where('id', 2)->value('discussion_id');
        $this->assertGreaterThan(0, $discussion);
        $this->assertSame([1], $this->database()->table('discussion_tag')->where('discussion_id', $discussion)->pluck('tag_id')->map(fn ($id) => (int) $id)->all());
        // The tag's own count moved, so Flarum's events ran.
        $this->assertSame(1, (int) $this->database()->table('tags')->where('id', 1)->value('discussion_count'));
        $this->assertSame(1, (int) $this->database()->table('discussions')->where('id', $discussion)->value('comment_count'));
    }

    #[Test]
    public function category_administration_is_for_admins(): void
    {
        $this->assertSame(403, $this->send($this->request('GET', '/api/depot/categories', ['authenticatedAs' => 2]))->getStatusCode());
        $this->assertSame(200, $this->send($this->request('GET', '/api/depot/categories', ['authenticatedAs' => 1]))->getStatusCode());
    }

    #[Test]
    public function a_screenshot_claiming_too_many_pixels_is_refused_before_decoding(): void
    {
        // A valid PNG header claiming 16000×16000, with no image data: what a
        // decompression bomb looks like before anything decodes it.
        $ihdr = pack('NNCCCCC', 16000, 16000, 8, 2, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr));
        $upload = new UploadedFile((new StreamFactory())->createStream($png), strlen($png), UPLOAD_ERR_OK, 'bomb.png', 'image/png');

        $this->app();
        $this->expectException(ValidationException::class);
        $this->app()->getContainer()->make(Store::class)->checkScreenshot($upload, 1048576);
    }

    #[Test]
    public function repositories_and_changelogs_are_parsed_strictly(): void
    {
        $this->assertTrue(GitHub::validRepo('restic/restic'));
        $this->assertFalse(GitHub::validRepo('../etc/passwd'));
        $this->assertFalse(GitHub::validRepo('owner/repo/extra'));

        $parsed = Changelog::parse("## Added\n- A thing (#12)\n## Bug fixes\n* A fix\nNot a bullet\n- Loose");
        $this->assertSame(['added' => ['A thing'], 'changed' => [], 'fixed' => ['A fix', 'Loose']], $parsed);
    }
}
