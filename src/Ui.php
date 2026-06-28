<?php

namespace GlpiPlugin\Gamification;

use User;

/**
 * Small presentation helpers shared across gamification front pages.
 */
class Ui
{
    /**
     * Render a user avatar — real profile picture when available,
     * otherwise a coloured circle with the user's initial.
     *
     * @param array  $u      Row with users_id, picture, firstname, realname.
     * @param int    $size   Pixel size; pass 0 to let CSS control it.
     * @param string $class  Extra CSS classes (e.g. "gx-ava tier-1").
     */
    public static function avatar(array $u, int $size = 40, string $class = 'gx-ava'): string
    {
        $picture = $u['picture'] ?? null;
        $url     = $picture ? User::getThumbnailURLForPicture($picture) : '';
        $style   = $size > 0 ? "width:{$size}px;height:{$size}px;" : '';

        if ($url !== '') {
            $safe = htmlspecialchars($url, ENT_QUOTES);
            return "<span class='{$class}' style='{$style}background-image:url(\"{$safe}\");background-size:cover;background-position:center'></span>";
        }

        $name = trim((string)($u['firstname'] ?? '')) ?: trim((string)($u['realname'] ?? '')) ?: 'U';
        $ini  = strtoupper(mb_substr($name, 0, 1));
        $fs   = $size > 0 ? 'font-size:' . max(11, (int)round($size * 0.42)) . 'px;' : '';
        return "<span class='{$class}' style='{$style}{$fs}'>{$ini}</span>";
    }
}
