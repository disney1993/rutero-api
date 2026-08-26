<?php

namespace App\Support;

class AvatarPalette
{
    // Debe coincidir con AVATAR_PALETTE en rutero-react/src/utils/avatar.js
    public const COLORS = [
        '#EF5350', '#EC407A', '#AB47BC', '#7E57C2',
        '#5C6BC0', '#42A5F5', '#29B6F6', '#26C6DA',
        '#26A69A', '#66BB6A', '#9CCC65', '#FFA726',
        '#FF7043', '#8D6E63', '#78909C',
    ];

    public static function random(): string
    {
        return self::COLORS[array_rand(self::COLORS)];
    }

    public static function isValid(?string $color): bool
    {
        return is_string($color) && preg_match('/^#[A-Fa-f0-9]{6}$/', $color) === 1;
    }
}
