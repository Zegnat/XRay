<?php

declare(strict_types = 1);

namespace p3k\XRay\Formats;

class YouTube extends Format
{
    private static $http;
    private static $key;
    private static $headers = [];
    private static $api = 'https://www.googleapis.com/youtube/v3/';

    public static function matches_host($url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        return preg_match('~^((m|www)\.)?youtu(be\.com|\.be)$~i', $host) === 1;
    }

    public static function matches($url): array
    {
        $url = array_merge([ 'path' => '/', 'query' => '' ], parse_url($url));
        $url['host'] = strtolower($url['host']);
        $url['path'] = explode('/', trim($url['path'], '/'));
        parse_str($url['query'], $url['query']);
        // Check for playlists: /embed/videoseries?list={ID} and /playlist?list={ID}
        if(($url['path'][0] === 'embed' &&
            !empty($url['path'][1]) &&
            $url['path'][1] === 'videoseries' ||
            $url['path'][0] === 'playlist') &&
            !empty($url['query']['list'])
        ) {
            return [ 'type' => 'feed', 'id' => $url['query']['list'] ];
        }
        $id = false;
        // Short link: youtu.be/{ID}
        if($url['host'] === 'youtu.be' && !empty($url['path'][0])) {
            $id = $url['path'][0];
        }
        // Simple links: /v/{ID} and /embed/{ID}
        if(in_array($url['path'][0], ['v', 'embed']) && !empty($url['path'][1])) {
            $id = $url['path'][1];
        }
        // Otherwise assume v parameter: ?v={ID}
        if(!empty($url['query']['v'])) {
            $id = $url['query']['v'];
        }
        if($id) {
            return [ 'type' => 'entry', 'id' => $id ];
        }
        // Nothing
        return [ 'type' => 'unknown' ];
    }

    public static function fetch(\p3k\HTTP $http, string $url, array $creds): array
    {
        if (!isset($creds['youtube_api_key'])) {
            return static::returnError(
                'YouTube credentials must be included in the request',
                400,
                'missing_parameters'
            );
        }

        $resource = static::matches($url);

        if ($resource['type'] === 'unknown') {
            return static::returnError(
                'This YouTube URL is not supported',
                400,
                'unsupported_url'
            );
        }

        static::$http = $http;
        if (isset($creds['youtube_api_referer'])) {
            static::$headers = [ 'Referer: ' . $creds['youtube_api_referer'] ];
        }
        static::$key = $creds['youtube_api_key'];

        $channels = [];
        $videos = [];
        $return = [];

        if ($resource['type'] === 'feed') {
            // Playlist information.
            $list = static::apiRequest('playlists', [ 'id' => $resource['id'] ]);
            if (!empty($list['error'])) {
                return $list;
            }
            $list = json_decode($list['body'], true);
            if (count($list['items']) !== 1) {
                return static::returnError('YouTube API did not return a playlist.');
            }
            $list['items'][0]['snippet']['id'] = $list['items'][0]['id'];
            $list = $list['items'][0]['snippet'];
            $return['feed'] = $list;
            $channels[] = $list['channelId'];
            // Video IDs.
            $videos = static::apiRequest('playlistItems', [
                'playlistId' => $resource['id'],
                'maxResults' => 15,
            ]);
            if (!empty($videos['error'])) {
                return $videos;
            }
            $videos = json_decode($videos['body'], true);
            $videos = array_map(function ($video) {
                return $video['snippet']['resourceId']['videoId'];
            }, $videos['items']);
        }

        if ($resource['type'] === 'entry') {
            $videos = [ $resource['id'] ];
        }

        // Collect all the video details.
        if (count($videos) > 0) {
            $videos = static::apiRequest('videos', [ 'id' => implode(',', $videos) ]);
            if (!empty($videos['error'])) {
                return $videos;
            }
            $videos = json_decode($videos['body'], true);
            $videos = array_map(function ($video) use (&$channels) {
                $channels[] = $video['snippet']['channelId'];
                $video['snippet']['id'] = $video['id'];
                return $video['snippet'];
            }, $videos['items']);
        }
        $return['videos'] = $videos;

        // Collect all the author details.
        if (count($channels) > 0) {
            $channels = array_unique($channels);
            $channels = static::apiRequest('channels', [
                'id' => implode(',', $channels),
                'maxResults' => count($channels),
            ]);
            if (!empty($channels['error'])) {
                return $channels;
            }
            $channels = json_decode($channels['body'], true);
            $channels = array_map(function ($user) {
                $user['snippet']['id'] = $user['id'];
                return $user['snippet'];
            }, $channels['items']);
        }
        $return['channels'] = $channels;

        return [
            'url' => $url,
            'body' => json_encode($return),
            'code' => 200,
        ];
    }

    private static function returnError(
        string $description,
        int $code = 400,
        string $error = 'youtube_error'
    ): array {
        return [
            'error' => $error,
            'error_description' => $description,
            'error_code' => $code,
        ];
    }

    private static function apiRequest(string $path, array $parameters): array
    {
        $parameters = array_merge([
            'key' => static::$key,
            'part' => 'snippet',
        ], $parameters);
        $response = static::$http->get(
            static::$api . $path . '?' . http_build_query($parameters),
            static::$headers
        );
        if ($response['code'] === 200) {
            return $response;
        }
        return static::returnError($response['body'], $response['code']);
    }

    public static function parse($responses, $url) {
        $data = json_decode($responses, true);

        if (!$data)
            return static::_unknown();

        // Start building the h-entry
        $entry = [
            'type' => 'entry',
            'name' => $data['videos'][0]['title'],
            'content' => $data['videos'][0]['description'],
            'url' => 'https://www.youtube.com/watch?v=' . $data['videos'][0]['id'], // Canonical URL, I think.
            'published' => $data['videos'][0]['publishedAt'], // Millisecond precision, is that alright?
            'video' => [
                [
                    'content-type' => 'text/html',
                    'url' => 'https://www.youtube.com/embed/' . $data['videos'][0]['id'],
                ]
            ],
            'author' => [
                'type' => 'card',
                'name' => $data['channels'][0]['title'],
                'photo' => $data['channels'][0]['thumbnails']['high'],
                'url' => 'https://www.youtube.com/channel/' . $data['channels'][0]['id'],
            ],
        ];

        if (isset($data['channels'][0]['customUrl'])) {
            $entry['author']['url'] = 'https://www.youtube.com/' . $data['channels'][0]['customUrl'];
        }
        if (isset($data['channels'][0]['tags'])) {
            $entry['category'] = $data['channels'][0]['tags'];
        }

        $thumb = null;
        if (isset($video->thumbnails->maxres)) {
            $thumb = $data['videos'][0]['thumbnails']['maxres'];
        } elseif (isset($video->thumbnails->standard)) {
            $thumb = $data['videos'][0]['thumbnails']['standard'];
        }
        if ($thumb !== null) {
            $entry['photo'] = [ $thumb['url'] ];
        }

        return [
            'data' => $entry,
            'original' => $responses,
        ];
    }
}
