<?php

declare(strict_types=1);

namespace App\Services\Leaderboard;

use App\DTOs\CustomField;

final class LeaderboardEligibility
{
    public const LABEL = 'Display in leaderboard';

    public const VALUE = 'TRUE';

    /**
     * @param  CustomField[]  $fields
     */
    public function isEligible(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->label === self::LABEL && $field->value === self::VALUE) {
                return true;
            }
        }

        return false;
    }
}
