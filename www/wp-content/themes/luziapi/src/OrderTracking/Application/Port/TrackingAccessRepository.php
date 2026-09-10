<?php

declare(strict_types=1);

namespace LuziApi\OrderTracking\Application\Port;

use DateTimeImmutable;

interface TrackingAccessRepository
{
    public function allowAttempt(
        string $scope,
        string $subjectFingerprint,
        int $limit,
        int $windowSeconds,
        DateTimeImmutable $at,
    ): bool;

    /** @param non-empty-list<int> $orderIds */
    public function issueMagicLink(
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void;

    /** @return non-empty-list<int>|null */
    public function consumeMagicLink(string $tokenFingerprint, DateTimeImmutable $at): ?array;

    /** @param non-empty-list<int> $orderIds */
    public function createSession(
        string $tokenFingerprint,
        array $orderIds,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $createdAt,
    ): void;

    /** @return non-empty-list<int>|null */
    public function sessionOrderIds(string $tokenFingerprint, DateTimeImmutable $at): ?array;

    public function revokeSession(string $tokenFingerprint): void;

    public function purgeExpired(DateTimeImmutable $at): void;
}
