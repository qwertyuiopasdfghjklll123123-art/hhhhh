const axios = require('axios');

// خدمة اختيارية: تبحث عن فيديو تعليمي حقيقي عبر YouTube Data API v3
// وتُستخدم لاعتماد رابط "موثوق" للمحاضرة بدل الاكتفاء باقتراح الذكاء الاصطناعي
// (الذي لا يمكنه التحقق من وجود الفيديو أو صحة رابطه).
// إن لم يُضبط YOUTUBE_API_KEY تُعاد null والنظام يستمر بعرض رابط بحث احتياطي.
async function searchLectureVideo(searchQuery) {
  const apiKey = process.env.YOUTUBE_API_KEY;
  if (!apiKey || !searchQuery) return null;

  try {
    const { data } = await axios.get('https://www.googleapis.com/youtube/v3/search', {
      params: {
        key: apiKey,
        q: searchQuery,
        part: 'snippet',
        type: 'video',
        maxResults: 5,
        relevanceLanguage: 'ar',
        safeSearch: 'strict',
        videoEmbeddable: 'true',
      },
      timeout: 15000,
    });

    const first = (data.items || [])[0];
    if (!first) return null;

    return {
      videoId: first.id.videoId,
      title: first.snippet.title,
      channelTitle: first.snippet.channelTitle,
      thumbnailUrl: first.snippet.thumbnails && first.snippet.thumbnails.medium
        ? first.snippet.thumbnails.medium.url
        : null,
    };
  } catch (err) {
    console.error('YouTube search failed:', err.message);
    return null;
  }
}

// رابط بحث احتياطي يُعرض للطالب حين لا يوجد فيديو معتمد بعد
function fallbackSearchUrl(searchQuery) {
  return `https://www.youtube.com/results?search_query=${encodeURIComponent(searchQuery)}`;
}

module.exports = { searchLectureVideo, fallbackSearchUrl };
