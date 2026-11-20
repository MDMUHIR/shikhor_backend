<?php

namespace App\Services;

/**
 * Validates and normalises lesson/promo video URLs. Only https URLs are accepted.
 */
class VideoUrlService
{
    /** Extract the 11-character YouTube id from watch/short/embed URLs, or null if not YouTube. */
    public function youtubeId(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $parts = parse_url(trim($url));

        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return null;
        }

        $host = strtolower(preg_replace('/^(www\.|m\.)/', '', $parts['host']));
        $path = $parts['path'] ?? '';
        $id = null;

        if (in_array($host, ['youtube.com', 'youtube-nocookie.com'], true)) {
            if ($path === '/watch') {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;
            } elseif (preg_match('#^/(embed|shorts|live|v)/([^/?]+)#', $path, $m)) {
                $id = $m[2];
            }
        } elseif ($host === 'youtu.be') {
            $id = ltrim($path, '/');
        }

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    public function youtubeEmbedUrl(string $id): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.$id.'?rel=0&modestbranding=1';
    }

    /** External (non-YouTube) videos must be plain https URLs. */
    public function isValidExternal(?string $url): bool
    {
        if (! $url || strlen($url) > 2048) {
            return false;
        }

        $parts = parse_url($url);

        return $parts && ($parts['scheme'] ?? '') === 'https' && ! empty($parts['host']);
    }
}
