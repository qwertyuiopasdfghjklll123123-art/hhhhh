// طبقة الاتصال بالـ API الخلفي
window.App = window.App || {};

App.api = (function () {
  async function request(path, { method = 'GET', body, auth = true } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (auth) {
      const token = App.state.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
    }

    let response;
    try {
      response = await fetch(App.config.API_BASE_URL + path, {
        method,
        headers,
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (err) {
      throw new Error('تعذّر الاتصال بالخادم. تأكد أن الـ backend يعمل وأن رابط API صحيح.');
    }

    let data = null;
    try {
      data = await response.json();
    } catch (err) {
      // استجابة بلا محتوى JSON
    }

    if (!response.ok) {
      const message = (data && data.error) || `خطأ (${response.status})`;
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }

    return data;
  }

  return {
    get: (path) => request(path, { method: 'GET' }),
    post: (path, body) => request(path, { method: 'POST', body }),
    put: (path, body) => request(path, { method: 'PUT', body }),

    // Auth
    register: (payload) => request('/auth/register', { method: 'POST', body: payload, auth: false }),
    login: (payload) => request('/auth/login', { method: 'POST', body: payload, auth: false }),
    me: () => request('/auth/me'),

    // Curriculum
    getCountries: () => request('/countries', { auth: false }),
    getStages: (countryId) => request(`/countries/${countryId}/stages`, { auth: false }),
    getSubjects: (stageId) => request(`/stages/${stageId}/subjects`, { auth: false }),
    getSubject: (subjectId) => request(`/subjects/${subjectId}`, { auth: false }),
    getUnits: (subjectId) => request(`/subjects/${subjectId}/units`, { auth: false }),
    getUnit: (unitId) => request(`/units/${unitId}`, { auth: false }),
    getLectures: (unitId) => request(`/units/${unitId}/lectures`, { auth: false }),
    generateCurriculum: (stageId) => request(`/stages/${stageId}/generate`, { method: 'POST' }),

    // Lecture
    getLecture: (id) => request(`/lectures/${id}`, { auth: false }),
    updateProgress: (id, payload) => request(`/lectures/${id}/progress`, { method: 'POST', body: payload }),

    // Quiz
    getQuiz: (lectureId) => request(`/lectures/${lectureId}/quiz`),
    submitQuiz: (quizId, payload) => request(`/quizzes/${quizId}/submit`, { method: 'POST', body: payload }),

    // Tutor
    getTutorHistory: (lectureId) => request(`/tutor/sessions/${lectureId}`),
    sendTutorMessage: (lectureId, message) =>
      request('/tutor/chat', { method: 'POST', body: { lectureId, message } }),

    // Points & Leaderboard
    getMyPoints: () => request('/points/me'),
    getLeaderboard: (scope, countryId) =>
      request(`/leaderboard?scope=${scope}${countryId ? `&countryId=${countryId}` : ''}`, { auth: false }),

    // Settings (admin)
    getDeepseekSettings: () => request('/settings/deepseek'),
    saveDeepseekSettings: (payload) => request('/settings/deepseek', { method: 'PUT', body: payload }),
    testDeepseekConnection: () => request('/settings/deepseek/test', { method: 'POST' }),
  };
})();
