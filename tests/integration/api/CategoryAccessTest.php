<?php

namespace ErnestDefoe\Depot\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Depot\Model\Category;
use Flarum\Group\Group;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Subcategories stricter than their parent, as a forum set them up: Books
 * and Magazines for everyone, Magazines › Intermediate for Group1 and
 * Group2, Magazines › Advanced for Group2 only. Each person sees, and can
 * upload to, exactly the categories their groups open; admins all of them.
 */
class CategoryAccessTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const ADMIN = 1;
    private const MEMBER = 2;
    private const GROUP1 = 3;
    private const GROUP2 = 4;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-depot');

        $now = Carbon::now();
        $file = fn (int $id, int $category, string $title) => [
            'id' => $id, 'category_id' => $category, 'user_id' => self::ADMIN, 'title' => $title, 'slug' => strtolower($title),
            'source' => 'link', 'approved_at' => $now, 'wants_thread' => 0, 'created_at' => $now,
        ];

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => self::GROUP1, 'username' => 'one', 'email' => 'one@machine.local', 'is_email_confirmed' => 1],
                ['id' => self::GROUP2, 'username' => 'two', 'email' => 'two@machine.local', 'is_email_confirmed' => 1],
            ],
            Group::class => [
                ['id' => 5, 'name_singular' => 'Group1', 'name_plural' => 'Group1'],
                ['id' => 6, 'name_singular' => 'Group2', 'name_plural' => 'Group2'],
            ],
            'group_user' => [['user_id' => self::GROUP1, 'group_id' => 5], ['user_id' => self::GROUP2, 'group_id' => 6], ['user_id' => self::ADMIN, 'group_id' => 6]],
            'depot_categories' => [
                ['id' => 1, 'name' => 'Books', 'slug' => 'books', 'position' => 0, 'file_count' => 1],
                ['id' => 2, 'name' => 'Magazines', 'slug' => 'magazines', 'position' => 1, 'file_count' => 0],
                ['id' => 3, 'parent_id' => 2, 'name' => 'Beginner', 'slug' => 'beginner', 'position' => 0, 'file_count' => 1],
                ['id' => 4, 'parent_id' => 2, 'name' => 'Intermediate', 'slug' => 'intermediate', 'position' => 1, 'file_count' => 1],
                ['id' => 5, 'parent_id' => 2, 'name' => 'Advanced', 'slug' => 'advanced', 'position' => 2, 'file_count' => 1],
            ],
            'depot_category_groups' => [
                ['category_id' => 4, 'group_id' => 5],
                ['category_id' => 4, 'group_id' => 6],
                ['category_id' => 5, 'group_id' => 6],
            ],
            'depot_files' => [$file(1, 1, 'Novel'), $file(2, 3, 'Primer'), $file(3, 4, 'Midway'), $file(4, 5, 'Expert')],
        ]);
    }

    private function browse(?int $as, array $query = []): array
    {
        $response = $this->send($this->request('GET', '/api/depot/browse', $as ? ['authenticatedAs' => $as] : [])->withQueryParams($query));
        $this->assertSame(200, $response->getStatusCode());

        return json_decode((string) $response->getBody(), true);
    }

    /** The categories the upload picker offers: open, with no subcategories. */
    private function pickable(?int $as): array
    {
        $categories = $this->browse($as)['categories'];
        $parents = array_filter(array_column($categories, 'parent'));

        $slugs = array_map(fn ($c) => $c['slug'], array_filter($categories, fn ($c) => $c['open'] && ! in_array($c['slug'], $parents, true)));
        sort($slugs);

        return $slugs;
    }

    private function titles(?int $as, string $category = ''): array
    {
        $titles = array_column($this->browse($as, $category ? ['category' => $category] : [])['files'], 'title');
        sort($titles);

        return $titles;
    }

    private function upload(int $as, string $category): int
    {
        return $this->send($this->request('POST', '/api/depot/files', [
            'authenticatedAs' => $as,
            'json' => ['source' => 'link', 'title' => "Upload to $category", 'category' => $category, 'version' => '1.0', 'link' => 'https://example.com/a.zip', 'thread' => '0'],
        ]))->getStatusCode();
    }

    #[Test]
    public function each_person_is_offered_the_categories_their_groups_open(): void
    {
        $this->assertSame(['beginner', 'books'], $this->pickable(null), 'a guest');
        $this->assertSame(['beginner', 'books'], $this->pickable(self::MEMBER), 'a plain member');
        $this->assertSame(['beginner', 'books', 'intermediate'], $this->pickable(self::GROUP1), 'Group1');
        $this->assertSame(['advanced', 'beginner', 'books', 'intermediate'], $this->pickable(self::GROUP2), 'Group2 only');
        $this->assertSame(['advanced', 'beginner', 'books', 'intermediate'], $this->pickable(self::ADMIN), 'an admin');
    }

    #[Test]
    public function a_parents_subcategory_chips_are_only_the_ones_each_person_opens(): void
    {
        // DepotPage::subnav(): the parent's children, kept when `open`.
        $chips = fn (?int $as) => array_values(array_column(array_filter(
            $this->browse($as, ['category' => 'magazines'])['categories'],
            fn ($c) => $c['parent'] === 'magazines' && $c['open']
        ), 'slug'));

        $this->assertSame(['beginner'], $chips(null), 'a guest');
        $this->assertSame(['beginner'], $chips(self::MEMBER), 'a plain member');
        $this->assertSame(['beginner', 'intermediate'], $chips(self::GROUP1), 'Group1');
        $this->assertSame(['beginner', 'intermediate', 'advanced'], $chips(self::GROUP2), 'Group2 only');
        $this->assertSame(['beginner', 'intermediate', 'advanced'], $chips(self::ADMIN), 'an admin');
    }

    #[Test]
    public function a_restricted_category_still_says_who_it_is_for(): void
    {
        $advanced = collect($this->browse(self::GROUP2)['categories'])->firstWhere('slug', 'advanced');

        $this->assertTrue($advanced['locked']);
        $this->assertTrue($advanced['open']);
        $this->assertSame(['Group2'], $advanced['groups']);
    }

    #[Test]
    public function each_person_sees_the_files_their_groups_open(): void
    {
        $this->assertSame(['Novel', 'Primer'], $this->titles(null));
        $this->assertSame(['Novel', 'Primer'], $this->titles(self::MEMBER));
        $this->assertSame(['Midway', 'Novel', 'Primer'], $this->titles(self::GROUP1));
        $this->assertSame(['Expert', 'Midway', 'Novel', 'Primer'], $this->titles(self::GROUP2));
        $this->assertSame(['Expert', 'Midway', 'Novel', 'Primer'], $this->titles(self::ADMIN));

        // The parent's page holds only the subcategories this person may open.
        $this->assertSame(['Midway', 'Primer'], $this->titles(self::GROUP1, 'magazines'));
        $this->assertSame(['Expert', 'Midway', 'Primer'], $this->titles(self::GROUP2, 'magazines'));
    }

    #[Test]
    public function the_server_takes_uploads_only_where_the_picker_offers(): void
    {
        $this->assertSame(422, $this->upload(self::MEMBER, 'intermediate'));
        $this->assertSame(422, $this->upload(self::GROUP1, 'advanced'));
        $this->assertSame(201, $this->upload(self::GROUP1, 'intermediate'));
        $this->assertSame(201, $this->upload(self::GROUP2, 'advanced'));
        $this->assertSame(201, $this->upload(self::ADMIN, 'advanced'));
        $this->assertSame(422, $this->upload(self::GROUP2, 'magazines'), 'a parent with subcategories');
    }

    #[Test]
    public function the_category_list_costs_the_same_queries_however_many_categories(): void
    {
        $count = function () {
            // The tree is kept per request; one test process makes two.
            foreach (['all' => null, 'locked' => []] as $property => $empty) {
                (new \ReflectionProperty(Category::class, $property))->setValue(null, $empty);
            }
            $db = $this->app()->getContainer()->make(ConnectionInterface::class);
            $db->flushQueryLog();
            $db->enableQueryLog();
            $this->browse(self::GROUP2);
            $n = count($db->getQueryLog());
            $db->disableQueryLog();

            return $n;
        };

        // The first request also stamps last_seen_at and reads asset revisions.
        $count();
        $few = $count();

        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        foreach (range(10, 29) as $id) {
            $db->table('depot_categories')->insert(['id' => $id, 'parent_id' => 2, 'name' => "Extra $id", 'slug' => "extra-$id", 'position' => $id, 'file_count' => 0]);
            $db->table('depot_category_groups')->insert(['category_id' => $id, 'group_id' => $id % 2 ? 5 : 6]);
        }

        $this->assertSame($few, $count());
    }
}
