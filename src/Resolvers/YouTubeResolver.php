<?php

namespace Goldnead\Smartlinks\Resolvers;

use Goldnead\Smartlinks\Contracts\Resolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * YouTube Data API search, optional (YOUTUBE_API_KEY, free quota).
 *
 * A title search is fuzzy, and a wrong link is worse than none. So only a
 * video from the artist's auto-generated "<Artist> - Topic" channel counts:
 * that is the label's own upload of exactly this recording.
 */
class YouTubeResolver implements Resolver
{
    public const API_URL = 'https://www.googleapis.com/youtube/v3/search';

    public function platform(): string
    {
        return 'youtube';
    }

    public function resolve(Track $track): Resolution
    {
        $key = config('smartlinks.services.youtube.key');

        if (! filled($key)) {
            return Resolution::none($this->platform(), Resolution::NOT_CONFIGURED);
        }

        if (! filled($track->title) || ! filled($track->artist)) {
            return Resolution::none($this->platform(), Resolution::MISSING_INPUT);
        }

        try {
            $response = Http::timeout((int) config('smartlinks.services.timeout', 10))
                ->acceptJson()
                ->get(self::API_URL, [
                    'part' => 'snippet',
                    'type' => 'video',
                    'maxResults' => 5,
                    'q' => $track->artist.' '.$track->title,
                    'key' => $key,
                ]);
        } catch (ConnectionException) {
            return Resolution::none($this->platform(), Resolution::HTTP_ERROR);
        }

        if (! $response->successful()) {
            return Resolution::none($this->platform(), Resolution::HTTP_ERROR);
        }

        $channel = mb_strtolower($track->artist.' - Topic');

        foreach ((array) $response->json('items', []) as $item) {
            $videoId = data_get($item, 'id.videoId');
            $itemChannel = mb_strtolower((string) data_get($item, 'snippet.channelTitle'));

            if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) === 1 && $itemChannel === $channel) {
                return Resolution::found($this->platform(), 'https://www.youtube.com/watch?v='.$videoId);
            }
        }

        return Resolution::none($this->platform(), Resolution::NO_CONFIDENT_MATCH);
    }
}
