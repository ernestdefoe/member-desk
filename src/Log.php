<?php

namespace ErnestDefoe\MemberDesk;

use ErnestDefoe\MemberDesk\Audit\AuditEntry;

/**
 * How a log entry looks to staff. Hashes never leave the server: the entry says only that one is
 * kept, and Hash check answers "was it this address?".
 */
class Log
{
    /** @return array<string, mixed> */
    public static function present(AuditEntry $e): array
    {
        $before = $e->before ?? [];
        unset($before['emailHash'], $before['ipHashes']);
        $after = $e->after ?? [];

        if ($e->action === 'spammer') {
            $after = [
                'posts' => count($after['posts'] ?? []),
                'discussions' => count($after['discussions'] ?? []),
                'bannedIps' => count($after['bannedIps'] ?? []),
                'restored' => ! empty($after['restored']),
            ];
        }

        return [
            'id' => $e->id,
            'action' => $e->action,
            'status' => $e->status,
            'batch' => $e->batch,
            'userId' => $e->user_id,
            'member' => $e->relationLoaded('member') && $e->member
                ? ['id' => $e->member->id, 'displayName' => $e->member->display_name, 'username' => $e->member->username, 'avatarUrl' => $e->member->avatar_url]
                : null,
            'actor' => $e->actor ? ['id' => $e->actor->id, 'displayName' => $e->actor->display_name, 'username' => $e->actor->username] : null,
            'before' => $before ?: null,
            'after' => $after ?: null,
            'erased' => $e->erased,
            'hashesKept' => ! empty($e->before['emailHash']),
            'createdAt' => $e->created_at->toIso8601String(),
        ];
    }
}
