<?php

namespace Tarot\Service;

use Tarot\Repository\SpreadRepository;
use Tarot\Repository\UserSpreadRepository;
use Tarot\Structure\Spread;
use Tarot\Structure\UserSpread;

/**
 * Copies a public spread into a reader's own spreads (My Spreads), so they can
 * change it to suit their practice without touching the library's.
 */
class SpreadCopyService
{
    /** A personal spread's name is capped at this (UserSpreadData). */
    private const int NAME_MAX_LENGTH = 200;

    public function __construct(
        private readonly SpreadRepository $spreads,
        private readonly UserSpreadRepository $userSpreads,
    ) {}

    /**
     * The new personal spread: the public one's description and positions (with
     * their descriptions), under a name the reader's spreads don't already use.
     * Null when there is no such public spread: only the library's can be copied
     * (a submission still waiting for review isn't in it).
     */
    public function copyToAccount(int $spreadId, int $userId): ?UserSpread
    {
        $spread = $this->spreads->get($spreadId);
        if (!($spread instanceof Spread)) {
            return null;
        }

        $taken = array_map(
            static fn(UserSpread $own): string => mb_strtolower($own->name),
            $this->userSpreads->listByUser($userId)
        );

        return $this->userSpreads->create($userId, [
            'name'        => self::copyName($spread->name, $taken),
            'description' => $spread->description,
            'positions'   => $spread->positions,
        ]);
    }

    /**
     * "Celtic Cross (copy)", then "Celtic Cross (copy 2)" and so on: the first
     * not in $taken. Beside the library's own in the spread picker, the suffix
     * tells the reader's copy apart; a long name is shortened to keep it.
     *
     * @param list<string> $taken The reader's spread names, lower-cased.
     */
    public static function copyName(string $name, array $taken): string
    {
        for ($n = 1;; $n++) {
            $suffix = $n === 1 ? ' (copy)' : " (copy {$n})";
            $candidate = mb_substr(trim($name), 0, self::NAME_MAX_LENGTH - mb_strlen($suffix)) . $suffix;

            if (!in_array(mb_strtolower($candidate), $taken, true)) {
                return $candidate;
            }
        }
    }
}
