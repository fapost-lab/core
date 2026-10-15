<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Staff\Notifications\StaffMessageNotification;
use Database\Seeders\TenantAclSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * The shell's notification bell: the unread count every console page shares, the list, and marking read — always the
 * signed-in user's own notifications, admins included.
 */
final class NotificationBellConsoleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TenantAclSeeder::class);

        Route::middleware('admin')->get('admin/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
        Route::middleware('console')->get('assistant/{assistant}/_probe', fn () => Inertia::render('Auth/Login', ['action' => 'probe']));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function actionUrls(): array
    {
        return [
            'path on this host'     => ['/admin/users', true],
            'https on another host' => ['https://panel.example.test/billing', true],
            'http in capitals'      => ['HTTP://panel.example.test', true],
            'javascript'            => ['javascript:alert(1)', false],
            'javascript mixed case' => ['JaVaScRiPt:alert(1)', false],
            'data'                  => ['data:text/html,<script>alert(1)</script>', false],
            'protocol-relative'     => ['//evil.example', false],
            'backslash after slash' => ['/\\evil.example', false],
            'tab after slash'       => ["/\t/evil.example", false],
            'newline after slash'   => ["/\n/evil.example", false],
            'leading space'         => [' /admin', false],
            'leading control char'  => ["\x01/admin", false],
            'tab inside https'      => ["https://panel.example.test/\tx", false],
            'other scheme'          => ['ftp://files.example.test', false],
        ];
    }

    public function test_every_console_page_shares_the_users_own_unread_count_in_both_panels(): void
    {
        $assistant = Assistant::factory()->create();
        $user      = $this->admin();
        $other     = $this->admin();
        $user->notifyNow(new StaffMessageNotification('First'));
        $user->notifyNow(new StaffMessageNotification('Second'));
        $this->store($user, ['title' => 'Read already'], readAt: Carbon::now());
        $other->notifyNow(new StaffMessageNotification('Not yours'));

        $this->actingAs($user);

        $admin = $this->get($this->panelUrl('/admin/_probe'))->assertOk()->viewData('page')['props']['notifications'];
        $this->assertSame([
            'unread'     => 2,
            'indexUrl'   => '/console/notifications',
            'countUrl'   => '/console/notifications/count',
            'readAllUrl' => '/console/notifications/read',
        ], $admin);

        $console = $this->get($this->panelUrl("/assistant/{$assistant->getKey()}/_probe"))->assertOk()->viewData('page')['props']['notifications'];
        $this->assertSame(2, $console['unread']);
    }

    public function test_the_list_holds_the_users_own_notifications_newest_first_as_plain_text(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        $filament = $this->store($user, [
            'title'     => 'Operator <b>needed</b>',
            'body'      => "<p>Chat &amp; reply</p>\n<script>alert(1)</script>",
            'status'    => 'danger',
            'iconColor' => 'danger',
            'actions'   => [['name' => 'open', 'label' => 'Open chat', 'url' => 'https://panel.example.test/chat']],
            'format'    => 'filament',
        ], createdAt: Carbon::parse('2026-10-01 10:00:00'));
        $limit = $this->store($user, [
            'title'  => 'Contacts limit reached',
            'body'   => '1 000 of 1 000 contacts.',
            'action' => ['label' => 'Open the panel', 'url' => '/admin'],
            'kind'   => 'limit_reached',
        ], createdAt: Carbon::parse('2026-10-02 10:00:00'), readAt: Carbon::parse('2026-10-03 10:00:00'));
        $unsafe = $this->store($user, [
            'title'   => 'Bad link',
            'actions' => [['url' => 'javascript:alert(1)'], ['url' => '//evil.example']],
        ], createdAt: Carbon::parse('2026-10-03 10:00:00'));
        $this->store($other, ['title' => 'Not yours']);

        $response = $this->actingAs($user)->getJson($this->panelUrl('/console/notifications'))->assertOk();

        $this->assertSame(2, $response->json('unread'));
        $this->assertSame([$unsafe, $limit, $filament], array_column($response->json('items'), 'id'));

        [$bad, $notice, $message] = $response->json('items');

        $this->assertSame([
            'id'        => $filament,
            'title'     => 'Operator needed',
            'body'      => 'Chat & reply alert(1)',
            'tone'      => 'danger',
            'action'    => ['label' => 'Open chat', 'url' => 'https://panel.example.test/chat'],
            'createdAt' => '2026-10-01T10:00:00+00:00',
            'read'      => false,
            'readUrl'   => "/console/notifications/{$filament}/read",
        ], $message);
        $this->assertSame('warning', $notice['tone']);
        $this->assertSame(['label' => 'Open the panel', 'url' => '/admin'], $notice['action']);
        $this->assertTrue($notice['read']);
        $this->assertNull($bad['action'], 'Only a web address or a path on this host becomes a link.');
        $this->assertSame('neutral', $bad['tone']);
    }

    #[DataProvider('actionUrls')]
    public function test_only_a_safe_address_becomes_a_link(string $url, bool $kept): void
    {
        $user = User::factory()->create();
        $this->store($user, ['title' => 'Link', 'action' => ['label' => 'Go', 'url' => $url]]);

        $action = $this->actingAs($user)->getJson($this->panelUrl('/console/notifications'))->assertOk()->json('items.0.action');

        $this->assertSame($kept ? ['label' => 'Go', 'url' => $url] : null, $action);
    }

    public function test_a_notification_written_by_the_notify_node_is_listed(): void
    {
        $user = User::factory()->create();
        $user->notifyNow(new StaffMessageNotification('Operator needed'));

        $item = $this->actingAs($user)->getJson($this->panelUrl('/console/notifications'))->assertOk()->json('items.0');

        $this->assertSame('Operator needed', $item['title']);
        $this->assertNull($item['body']);
        $this->assertSame('info', $item['tone']);
        $this->assertNull($item['action']);
    }

    public function test_the_user_marks_their_own_notification_read(): void
    {
        $user = User::factory()->create();
        $id   = $this->store($user, ['title' => 'One']);
        $this->store($user, ['title' => 'Two']);

        $this->actingAs($user)->postJson($this->panelUrl("/console/notifications/{$id}/read"))
            ->assertOk()
            ->assertExactJson(['unread' => 1]);

        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->getJson($this->panelUrl('/console/notifications/count'))->assertExactJson(['unread' => 1]);
    }

    public function test_nobody_marks_another_users_notification_read_not_even_an_admin(): void
    {
        $owner = User::factory()->create();
        $id    = $this->store($owner, ['title' => 'Private']);

        $this->actingAs($this->admin())->postJson($this->panelUrl("/console/notifications/{$id}/read"))->assertNotFound();
        $this->postJson($this->panelUrl('/console/notifications/not-a-uuid/read'))->assertNotFound();

        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
    }

    public function test_mark_all_read_touches_only_the_users_own_notifications(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $this->store($user, ['title' => 'One']);
        $this->store($user, ['title' => 'Two']);
        $theirs = $this->store($other, ['title' => 'Theirs']);

        $this->actingAs($user)->postJson($this->panelUrl('/console/notifications/read'))
            ->assertOk()
            ->assertExactJson(['unread' => 0]);

        $this->assertSame(0, $user->unreadNotifications()->count());
        $this->assertNull(DB::table('notifications')->where('id', $theirs)->value('read_at'));
    }

    public function test_a_guest_has_no_inbox(): void
    {
        $this->getJson($this->panelUrl('/console/notifications'))->assertUnauthorized();
        $this->getJson($this->panelUrl('/console/notifications/count'))->assertUnauthorized();
        $this->postJson($this->panelUrl('/console/notifications/read'))->assertUnauthorized();
    }

    public function test_every_bell_route_is_throttled(): void
    {
        foreach (['index', 'count', 'read', 'read-all'] as $action) {
            $route = Route::getRoutes()->getByName("console.notifications.{$action}");

            $this->assertNotNull($route, $action);
            $this->assertContains('throttle:60,1', $route->gatherMiddleware(), $action);
        }
    }

    /**
     * Stores a row the way a database notification does, in whatever shape the test needs.
     *
     * @param  array<string, mixed>  $data
     */
    private function store(User $user, array $data, ?Carbon $createdAt = null, ?Carbon $readAt = null): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id'              => $id,
            'type'            => StaffMessageNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id'   => $user->getKey(),
            'data'            => json_encode($data, JSON_THROW_ON_ERROR),
            'read_at'         => $readAt,
            'created_at'      => $createdAt ?? Carbon::now(),
            'updated_at'      => $createdAt ?? Carbon::now(),
        ]);

        return $id;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }
}
