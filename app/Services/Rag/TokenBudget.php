<?php

namespace App\Services\Rag;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;

/**
 * A daily cap on LLM tokens, per user and for the whole app, so an open
 * deployment can't run up an unbounded bill. A limit of 0 means unlimited.
 *
 * Each question reserves an upper estimate of its cost before it starts,
 * and the reservation is replaced by the real usage once the model reports
 * it. Reserving is an atomic increment followed by a check, so questions
 * running at the same time can't all slip under the limit together.
 * Counters live in the cache and expire after the day.
 */
class TokenBudget
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $dailyPerUser = 0,
        private readonly int $dailyTotal = 0,
    ) {}

    /**
     * Reserve tokens for one question. Returns false, reserving nothing, when
     * the reservation would take the user or the app over its daily limit.
     */
    public function reserve(User $user, int $tokens): bool
    {
        $reserved = [];

        foreach ($this->limits($user) as $key => $limit) {
            $this->cache->add($key, 0, now()->endOfDay());
            $used = (int) $this->cache->increment($key, $tokens);
            $reserved[] = $key;

            if ($limit > 0 && $used > $limit) {
                foreach ($reserved as $reservedKey) {
                    $this->cache->decrement($reservedKey, $tokens);
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Replace a reservation with the tokens the question actually used.
     */
    public function settle(User $user, int $reserved, int $used): void
    {
        $difference = $used - $reserved;

        foreach (array_keys($this->limits($user)) as $key) {
            match (true) {
                $difference > 0 => $this->cache->increment($key, $difference),
                $difference < 0 => $this->cache->decrement($key, -$difference),
                default => null,
            };
        }
    }

    public function usedBy(User $user): int
    {
        return (int) $this->cache->get($this->userKey($user), 0);
    }

    /**
     * @return array<string, int> Counter key => daily limit.
     */
    private function limits(User $user): array
    {
        return [
            $this->userKey($user) => $this->dailyPerUser,
            'token-budget:'.now()->toDateString().':total' => $this->dailyTotal,
        ];
    }

    private function userKey(User $user): string
    {
        return 'token-budget:'.now()->toDateString().':user:'.$user->id;
    }
}
