<?php

declare(strict_types=1);

namespace LuziApi\Tests\Loyalty;

use LuziApi\Loyalty\Application\Port\LoyaltyIdentityLinks;

/**
 * Liens d'identité en mémoire pour les tests. Reproduit fidèlement la sémantique
 * de l'adaptateur : chaque clé pointe vers une canonique, l'union fusionne les
 * groupes vers la plus petite clé, `expand` renvoie l'union des groupes touchés.
 */
final class InMemoryLoyaltyIdentityLinks implements LoyaltyIdentityLinks
{
    /** @var array<string, string> clé => canonique */
    public array $links = [];

    public function expand(array $keys): array
    {
        $keys = $this->clean($keys);
        if ([] === $keys) {
            return [];
        }

        $canonicals = [];
        foreach ($keys as $key) {
            if (isset($this->links[$key])) {
                $canonicals[$this->links[$key]] = true;
            }
        }
        if ([] === $canonicals) {
            return $keys;
        }

        $group = [];
        foreach ($this->links as $key => $canonical) {
            if (isset($canonicals[$canonical])) {
                $group[] = $key;
            }
        }

        return array_values(array_unique(array_merge($keys, $group)));
    }

    public function autoLink(string $emailKey, string $phoneKey): void
    {
        if ('' === $emailKey || '' === $phoneKey) {
            return;
        }
        if (isset($this->links[$phoneKey])) {
            return; // téléphone déjà rattaché : pas d'absorption d'un 2ᵉ e-mail
        }

        $this->union([$emailKey, $phoneKey]);
    }

    public function unlink(array $keys): void
    {
        $detach = $this->clean($keys);
        if ([] === $detach) {
            return;
        }

        $canonicals = [];
        foreach ($detach as $key) {
            if (isset($this->links[$key])) {
                $canonicals[$this->links[$key]] = true;
            }
        }
        if ([] === $canonicals) {
            return;
        }

        $all = [];
        foreach ($this->links as $key => $canonical) {
            if (isset($canonicals[$canonical])) {
                $all[] = $key;
            }
        }
        $detachSet = array_fill_keys($detach, true);
        $remaining = array_values(array_filter($all, static fn (string $key): bool => ! isset($detachSet[$key])));

        $this->repoint($detach);
        $this->repoint($remaining);
    }

    /** @param list<string> $keys */
    private function repoint(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->links[$key]);
        }
        if (count($keys) < 2) {
            return;
        }

        sort($keys);
        $merged = $keys[0];
        foreach ($keys as $key) {
            $this->links[$key] = $merged;
        }
    }

    public function union(array $keys): void
    {
        $keys = $this->clean($keys);
        if (count($keys) < 2) {
            return;
        }

        $existing = [];
        foreach ($keys as $key) {
            if (isset($this->links[$key])) {
                $existing[$this->links[$key]] = true;
            }
        }
        $candidates = array_merge($keys, array_keys($existing));
        sort($candidates);
        $merged = $candidates[0];

        foreach ($this->links as $key => $canonical) {
            if (isset($existing[$canonical])) {
                $this->links[$key] = $merged;
            }
        }
        foreach ($keys as $key) {
            $this->links[$key] = $merged;
        }
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function clean(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, static fn (string $key): bool => '' !== $key)));
    }
}
