<?php
namespace p3k\XRay\Formats;

class YouTube extends Format {

  public static function matches_host($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return preg_match('~^(www\.)?youtu(be\.com|\.be)$~i', $host) === 1;
  }

  public static function matches($url) {
    if(preg_match('~^https?://(?:(?:m|www)\.)?youtu(?:be\.com/(?:embed/|v/|watch\?(?:.+&)?v=)|\.be/)([a-z0-9_\-]+)~i', $url, $match))
      return $match;
    else
      return false;
  }

  public static function fetch($http, $url, $creds) {
    $video = static::matches($url);

    if(!$video) {
      return [
        'error' => 'unsupported_url',
        'error_description' => 'This YouTube URL is not supported',
        'error_code' => 400,
      ];
    }

    $headers = [];
    if(isset($creds['youtube_api_referer'])) {
      $headers[] = 'Referer: ' . $creds['youtube_api_referer'];
    }

    $params = [
      'id' => $video[1],
      'part' => 'snippet',
    ];
    if(isset($creds['youtube_api_key'])) {
      $params['key'] = $creds['youtube_api_key'];
    }
    $request = 'https://www.googleapis.com/youtube/v3/videos?' . http_build_query($params);

    $response1 = $http->get($request, $headers);
    if($response1['code'] !== 200) {
      return [
        'error' => 'youtube_error',
        'error_description' => $response1['body'],
        'code' => $response1['code'],
      ];
    }

    $params = [
      'id' => json_decode($response1['body'])->items[0]->snippet->channelId,
      'part' => 'snippet',
    ];
    if(isset($creds['youtube_api_key'])) {
      $params['key'] = $creds['youtube_api_key'];
    }
    $request = 'https://www.googleapis.com/youtube/v3/channels?' . http_build_query($params);
    $response2 = $http->get($request, $headers);

    if($response2['code'] !== 200) {
      return [
        'error' => 'youtube_error',
        'error_description' => $response2['body'],
        'code' => $response2['code'],
      ];
    }

    return [
      'url' => $url,
      'body' => json_encode([$response1['body'], $response2['body']]),
      'code' => 200,
    ];
  }

  public static function parse($responses, $url) {
    $data = array_map('json_decode', json_decode($responses, true));

    if(!$data)
      return static::_unknown();

    $videoid = $data[0]->items[0]->id;
    $video = $data[0]->items[0]->snippet;
    $channelid = $data[1]->items[0]->id;
    $channel = $data[1]->items[0]->snippet;

    // Start building the h-entry
    $entry = [
      'type' => 'entry',
      'name' => $video->title,
      'content' => $video->description,
      'url' => 'https://www.youtube.com/watch?v=' . $videoid, // Canonical URL, I think.
      'published' => $video->publishedAt, // Millisecond precision, is that alright?
      'video' => [
        [
          'content-type' => 'text/html',
          'url' => 'https://www.youtube.com/embed/' . $videoid,
        ]
      ],
      'author' => [
        'type' => 'card',
        'name' => $channel->title,
        'photo' => $channel->thumbnails->high,
        'url' => 'https://www.youtube.com/channel/' . $channelid,
      ],
    ];

    if(isset($channel->customUrl)) {
      $entry['author']['url'] = 'https://www.youtube.com/' . $channel->customUrl;
    }
    if(isset($video->tags)) {
      $entry['category'] = $video->tags;
    }

    $thumb = null;
    if(isset($video->thumbnails->maxres)) {
      $thumb = $video->thumbnails->maxres;
    }elseif(isset($video->thumbnails->standard)) {
      $thumb = $video->thumbnails->standard;    
    }
    if($thumb !== null) {
      $entry['photo'] = [ $thumb->url ];
    }

    return [
      'data' => $entry,
      'original' => $responses
    ];
  }

}
