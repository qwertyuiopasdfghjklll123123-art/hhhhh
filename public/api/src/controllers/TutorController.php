<?php

class TutorController
{
    private static function getOrCreateSession($userId, $lectureId)
    {
        $existing = Database::one(
            'SELECT * FROM ai_chat_sessions WHERE user_id = ? AND lecture_id = ?
             ORDER BY created_at DESC LIMIT 1',
            [$userId, $lectureId]
        );
        if ($existing) {
            return $existing;
        }
        Database::run('INSERT INTO ai_chat_sessions (user_id, lecture_id) VALUES (?, ?)', [$userId, $lectureId]);
        return ['id' => Database::lastInsertId(), 'user_id' => $userId, 'lecture_id' => $lectureId];
    }

    public static function history($params, $body, $user)
    {
        $session = Database::one(
            'SELECT id FROM ai_chat_sessions WHERE user_id = ? AND lecture_id = ?
             ORDER BY created_at DESC LIMIT 1',
            [$user['id'], $params['lectureId']]
        );
        if (!$session) {
            Response::json(['sessionId' => null, 'messages' => []]);
        }

        $messages = Database::all(
            'SELECT role, message_text, created_at FROM ai_chat_messages
             WHERE session_id = ? ORDER BY created_at ASC',
            [$session['id']]
        );
        Response::json(['sessionId' => (int) $session['id'], 'messages' => $messages]);
    }

    public static function chat($params, $body, $user)
    {
        $lectureId = $body['lectureId'] ?? null;
        $message = trim($body['message'] ?? '');
        if (!$lectureId || !$message) {
            throw new ApiException(400, 'يجب تحديد المحاضرة ونص السؤال');
        }

        $lecture = Database::one('SELECT * FROM lectures WHERE id = ?', [$lectureId]);
        if (!$lecture) {
            throw new ApiException(404, 'المحاضرة غير موجودة');
        }

        $session = self::getOrCreateSession($user['id'], $lectureId);

        $history = Database::all(
            'SELECT role, message_text FROM ai_chat_messages WHERE session_id = ?
             ORDER BY created_at ASC LIMIT 20',
            [$session['id']]
        );

        Database::run(
            "INSERT INTO ai_chat_messages (session_id, role, message_text) VALUES (?, 'user', ?)",
            [$session['id'], $message]
        );

        $result = DeepSeekService::tutorReply(
            $lecture['title_ar'],
            $lecture['description'],
            $lecture['transcript_text'],
            $history,
            $message
        );

        Database::run(
            "INSERT INTO ai_chat_messages (session_id, role, message_text, tokens_used) VALUES (?, 'assistant', ?, ?)",
            [$session['id'], $result['reply'], $result['usage']['total_tokens'] ?? null]
        );

        Response::json(['sessionId' => (int) $session['id'], 'reply' => $result['reply']]);
    }
}
