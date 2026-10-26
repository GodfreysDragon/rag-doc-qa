<?php

namespace App\Services\Rag;

use App\Models\User;
use Illuminate\Contracts\Cache\Repository;

/**
 * A daily cap on LLM tokens, per user and for the whole app, so an open
 * deployment can't run up an unbounded bill. Usage is counted from the
 * token totals the model reports when an answer finishes; a limit of 0
 * means unlimited. Counters live in the cache and expire after the day.
 */
class TokenBudget
{
    public function __construct(
        private readonly Repository $cache,
        private readonly int $dailyPerUser = 0,
        private readonly int $dailyTotal = 0,
    ) {}

    public function isExhausted(User $user): bool
    {
        return ($this->dailyPerUser > 0 && $this->used($this->userKey($user)) >= $this->dailyPerUser)
            || ($this->dailyTotal > 0 && $this->used($this->totalKey()) >= $this->dailyTotal);
    }

    public function record(User $user, int $tokens): void
    {
        if ($tokens <= 0) {
            return;
        }

        foreach ([$this->userKey($user), $this->totalKey()] as $key) {
            $this->cache->add($key, 0, now()->endOfDay());
            $this->cache->increment($key, $tokens);
        }
    }

    public function usedBy(User $user): int
    {
        return $this->used($this->userKey($user));
    }

    private function used(string $key): int
    {
        return (int) $this->cache->get($key, 0);
    }

    private function userKey(User $user): string
    {
        return 'token-budget:'.now()->toDateString().':user:'.$user->id;
    }

    private function totalKey(): string
    {
        return 'token-budget:'.now()->toDateString().':total';
    }
}
