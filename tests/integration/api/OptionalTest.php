<?php

namespace ErnestDefoe\MemberDesk\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * With fof/ban-ips and flarum/audit on. Users: 1 admin, 4 spam1 + 5 spam2 (both on
 * 198.51.100.23), 6 innocent (shares 198.51.100.77 with spam1).
 */
class OptionalTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-suspend', 'flarum-audit', 'fof-ban-ips', 'ernestdefoe-member-desk');

        $now = Carbon::now();
        $token = fn (int $userId, string $ip) => [
            'token' => bin2hex(random_bytes(20)), 'user_id' => $userId, 'last_activity_at' => $now, 'created_at' => $now,
            'type' => 'session', 'last_ip_address' => $ip, 'last_user_agent' => 'test',
        ];
        $this->prepareDatabase([
            User::class => [
                ['id' => 4, 'username' => 'spam1', 'email' => 'spam1@machine.local', 'is_email_confirmed' => 0],
                ['id' => 5, 'username' => 'spam2', 'email' => 'spam2@machine.local', 'is_email_confirmed' => 0],
                ['id' => 6, 'username' => 'innocent', 'email' => 'innocent@machine.local', 'is_email_confirmed' => 1],
            ],
            'access_tokens' => [
                $token(4, '198.51.100.23'),
                $token(4, '198.51.100.77'),
                $token(5, '198.51.100.23'),
                $token(6, '198.51.100.77'),
            ],
        ]);
    }

    protected function allowedRepeatedQueries(): array
    {
        // One core user update per member acted on (see MemberDeskTest).
        return ['users"."id" = ? limit ?', 'users`.`id` = ? limit ?'];
    }

    private function runAction(string $action, array $ids): array
    {
        $r = $this->send($this->request('POST', '/api/member-desk/run', ['authenticatedAs' => 1, 'json' => ['action' => $action, 'target' => ['ids' => $ids]]]));

        return json_decode((string) $r->getBody(), true);
    }

    #[Test]
    public function a_spam_ring_ip_is_banned_but_one_an_active_member_shares_is_not(): void
    {
        $body = $this->runAction('spammer', [4, 5]);
        $this->assertSame(2, $body['done'], json_encode($body));

        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $banned = $db->table('banned_ips')->pluck('address')->all();
        $this->assertContains('198.51.100.23', $banned, 'banned once every account on it was marked');
        $this->assertNotContains('198.51.100.77', $banned, 'innocent still uses it');

        // Banned-IP filter and flag now see them.
        $r = $this->send($this->request('GET', '/api/member-desk/members', ['authenticatedAs' => 1])->withQueryParams(['status' => ['banned']]));
        $list = json_decode((string) $r->getBody(), true);
        $this->assertEqualsCanonicalizing([4, 5], array_column($list['rows'], 'id'));

        // Restoring spam2 takes back only the bans its own action placed.
        $this->runAction('unspam', [5]);
        $this->assertSame(
            $db->table('banned_ips')->where('user_id', 5)->count(),
            0
        );
    }

    #[Test]
    public function every_action_is_mirrored_into_flarums_audit_log(): void
    {
        $this->runAction('confirm', [4]);
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $this->assertTrue($db->table('audit_log')->where('action', 'member_desk.confirm')->exists());
    }
}
