<?php
/**
 * جدول المسارات: [الطريقة, النمط, المعالج, خيارات]
 * الخيارات: auth (يتطلب دخولاً) / admin (يتطلب صلاحية أدمن، مع auth) / optionalAuth (دخول اختياري)
 * هذا الجدول يطابق تماماً نسخة Node.js (نفس المسارات ونفس شكل الردود) كي تعمل
 * ملفات public/js الحالية دون أي تعديل مع أي من الخادمين.
 */
return [
    ['GET', '/health', function ($params, $body, $user) {
        Response::json(['ok' => true, 'service' => 'smart-elearning-api-php']);
    }, []],

    // Auth
    ['POST', '/auth/register', ['AuthController', 'register'], []],
    ['POST', '/auth/login', ['AuthController', 'login'], []],
    ['GET', '/auth/me', ['AuthController', 'me'], ['auth' => true]],

    // Curriculum
    ['GET', '/countries', ['CurriculumController', 'countries'], []],
    ['GET', '/countries/{countryId}/stages', ['CurriculumController', 'stages'], []],
    ['GET', '/subjects/{subjectId}', ['CurriculumController', 'subjectDetail'], []],
    ['GET', '/units/{unitId}', ['CurriculumController', 'unitDetail'], []],
    ['GET', '/stages/{stageId}/subjects', ['CurriculumController', 'subjects'], []],
    ['GET', '/subjects/{subjectId}/units', ['CurriculumController', 'units'], []],
    ['GET', '/units/{unitId}/lectures', ['CurriculumController', 'lectures'], []],
    ['POST', '/stages/{stageId}/generate', ['CurriculumController', 'generate'], ['auth' => true, 'admin' => true]],

    // Lectures
    ['GET', '/lectures/{id}', ['LectureController', 'detail'], ['optionalAuth' => true]],
    ['POST', '/lectures/{id}/progress', ['LectureController', 'updateProgress'], ['auth' => true]],

    // Quiz
    ['GET', '/lectures/{lectureId}/quiz', ['QuizController', 'getQuiz'], ['auth' => true]],
    ['POST', '/quizzes/{id}/submit', ['QuizController', 'submit'], ['auth' => true]],

    // Points
    ['GET', '/points/me', ['PointsController', 'me'], ['auth' => true]],

    // Leaderboard
    ['GET', '/leaderboard', ['LeaderboardController', 'index'], ['optionalAuth' => true]],

    // AI Tutor
    ['GET', '/tutor/sessions/{lectureId}', ['TutorController', 'history'], ['auth' => true]],
    ['POST', '/tutor/chat', ['TutorController', 'chat'], ['auth' => true]],

    // Settings (admin)
    ['GET', '/settings/deepseek', ['SettingsController', 'get'], ['auth' => true, 'admin' => true]],
    ['PUT', '/settings/deepseek', ['SettingsController', 'save'], ['auth' => true, 'admin' => true]],
    ['POST', '/settings/deepseek/test', ['SettingsController', 'test'], ['auth' => true, 'admin' => true]],
];
