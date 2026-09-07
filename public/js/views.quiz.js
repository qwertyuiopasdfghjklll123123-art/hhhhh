// صفحة الاختبار: عرض الأسئلة، الاختيار، التصحيح الفوري والنقاط
window.App = window.App || {};
App.views = App.views || {};

App.views.quiz = async function quiz({ lectureId }) {
  const view = document.getElementById('view');
  view.innerHTML = App.ui.loadingHtml('جاري تجهيز الاختبار بالذكاء الاصطناعي...');

  const { quiz: quizData, questions } = await App.api.getQuiz(lectureId);
  const startedAt = new Date().toISOString();
  const selected = {}; // questionId -> optionId

  function renderQuestions(submittedResults) {
    const resultMap = submittedResults ? new Map(submittedResults.map((r) => [r.questionId, r])) : null;

    return questions
      .map((q, qi) => {
        const result = resultMap ? resultMap.get(q.id) : null;
        const optionsHtml = q.options
          .map((o) => {
            let cls = '';
            if (result) {
              if (o.id === result.correctOptionId) cls = 'correct';
              else if (o.id === result.selectedOptionId && !result.isCorrect) cls = 'incorrect';
            } else if (selected[q.id] === o.id) {
              cls = 'selected';
            }
            return `<div class="option-row ${cls}" data-question="${q.id}" data-option="${o.id}" ${result ? 'style="pointer-events:none"' : ''}>
              <span class="option-mark">${result && o.id === result.correctOptionId ? '<i class="fas fa-check"></i>' : result && cls === 'incorrect' ? '<i class="fas fa-xmark"></i>' : ''}</span>
              <span>${App.ui.escapeHtml(o.option_text)}</span>
            </div>`;
          })
          .join('');

        return `<div class="question-card an">
          <div class="quiz-progress">السؤال ${qi + 1} من ${questions.length}</div>
          <div class="question-text">${App.ui.escapeHtml(q.question_text)}</div>
          ${optionsHtml}
          ${result && result.explanation ? `<div class="option-explain"><i class="fas fa-lightbulb" style="color:var(--gold)"></i> ${App.ui.escapeHtml(result.explanation)}</div>` : ''}
        </div>`;
      })
      .join('');
  }

  function attachOptionHandlers() {
    view.querySelectorAll('.option-row').forEach((row) => {
      row.addEventListener('click', () => {
        const qId = Number(row.dataset.question);
        const oId = Number(row.dataset.option);
        selected[qId] = oId;
        view.querySelectorAll(`.option-row[data-question="${qId}"]`).forEach((r) => r.classList.remove('selected'));
        row.classList.add('selected');
        updateSubmitState();
      });
    });
  }

  function updateSubmitState() {
    const submitBtn = document.getElementById('submitQuizBtn');
    if (!submitBtn) return;
    const answeredCount = Object.keys(selected).length;
    submitBtn.disabled = answeredCount < questions.length;
    submitBtn.textContent = answeredCount < questions.length
      ? `أجب على كل الأسئلة (${answeredCount}/${questions.length})`
      : 'تصحيح الاختبار';
  }

  function renderForm() {
    view.innerHTML = `
      <div class="an">
        <div class="breadcrumb"><a href="#/lecture/${lectureId}">العودة للمحاضرة</a></div>
        <div class="page-title">${App.ui.escapeHtml(quizData.title)}</div>
        <div class="page-sub">أجب على جميع الأسئلة ثم اضغط تصحيح لمعرفة نتيجتك فوراً</div>
        <div id="questionsHolder">${renderQuestions(null)}</div>
        <button class="btn btn-primary btn-block" id="submitQuizBtn" disabled>أجب على كل الأسئلة (0/${questions.length})</button>
      </div>
    `;
    attachOptionHandlers();
    document.getElementById('submitQuizBtn').addEventListener('click', handleSubmit);
  }

  async function handleSubmit() {
    const submitBtn = document.getElementById('submitQuizBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner"></span> جاري التصحيح...';
    try {
      const answers = Object.entries(selected).map(([questionId, selectedOptionId]) => ({
        questionId: Number(questionId),
        selectedOptionId,
      }));
      const result = await App.api.submitQuiz(quizData.id, { answers, startedAt });
      renderResult(result);
    } catch (err) {
      if (!App.ui.handleAuthError(err)) App.ui.toast(err.message, 'err');
      submitBtn.disabled = false;
      submitBtn.textContent = 'تصحيح الاختبار';
    }
  }

  function renderResult(result) {
    const percentage = Math.round((result.correctCount / result.totalQuestions) * 100);
    view.innerHTML = `
      <div class="an">
        <div class="result-hero">
          <div class="result-ring"><b>${percentage}%</b></div>
          <div style="font-weight:800;font-size:1rem">${result.correctCount} من ${result.totalQuestions} إجابات صحيحة</div>
          <div class="result-points"><i class="fas fa-star"></i> +${result.pointsEarned} نقطة${result.perfectBonus ? ' (شامل مكافأة العلامة الكاملة 🏆)' : ''}</div>
        </div>
        <div id="questionsHolder">${renderQuestions(result.results)}</div>
        <div style="display:flex;gap:10px;margin-top:6px">
          <a class="btn btn-outline btn-block" href="#/lecture/${lectureId}">العودة للمحاضرة</a>
          <a class="btn btn-primary btn-block" href="#/leaderboard">لوحة المتصدرين</a>
        </div>
      </div>
    `;
    App.state.updateUser({ pointsTotal: (App.state.getUser().pointsTotal || 0) + result.pointsEarned });
    App.main.refreshHeader();
  }

  if (!questions.length) {
    view.innerHTML = App.ui.emptyStateHtml('fa-clipboard-question', 'لا يوجد اختبار متاح حالياً', 'حاول لاحقاً');
    return;
  }

  renderForm();
};
