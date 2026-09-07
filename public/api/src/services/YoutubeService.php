<?php

// خدمة اختيارية: تبحث عن فيديو تعليمي حقيقي عبر YouTube Data API v3
// وتُستخدم لاعتماد رابط "موثوق" للمحاضرة بدل الاكتفاء باقتراح الذكاء الاصطناعي
// (الذي لا يمكنه التحقق من وجود الفيديو أو صحة رابطه).
// إن لم يُضبط youtube_api_key في config.php تُعاد null والنظام يستمر برابط بحث احتياطي.
class YoutubeService
{
    public static function searchLectureVideo($searchQuery)
    {
        $apiKey = Config::get('youtube_api_key');
        if (!$apiKey || !$searchQuery) {
            return null;
        }

        $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
            'key' => $apiKey,
            'q' => $searchQuery,
            'part' => 'snippet',
            'type' => 'video',
            'maxResults' => 5,
            'relevanceLanguage' => 'ar',
            'safeSearch' => 'strict',
            'videoEmbeddable' => 'true',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            error_log('[smart-elearning-api] YouTube search failed: HTTP ' . $status);
            return null;
        }

        $data = json_decode($response, true);
        $first = $data['items'][0] ?? null;
        if (!$first) {
            return null;
        }

        return [
            'videoId' => $first['id']['videoId'],
            'title' => $first['snippet']['title'],
            'channelTitle' => $first['snippet']['channelTitle'],
            'thumbnailUrl' => $first['snippet']['thumbnails']['medium']['url'] ?? null,
        ];
    }

    public static function fallbackSearchUrl($searchQuery)
    {
        return 'https://www.youtube.com/results?search_query=' . urlencode($searchQuery);
    }
}
