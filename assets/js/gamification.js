/* =================================================================
   Gamification System — Child Users (Ages 0-12)
   SDO Quirino Library Management System
   -----------------------------------------------------------------
   Features:
   - 25-question educational quiz bank (library & general knowledge)
   - Star accumulation with localStorage persistence
   - Achievement badges at milestone star counts
   - Non-intrusive quiz bubble (bottom-right, never interrupts tasks)
   - Star counter injected into sidebar
   - Encouraging messages for correct and incorrect answers
================================================================= */

(function () {
  'use strict';

  // Only activate for child users
  if (window.uiTier !== 'child') return;

  /* ── Quiz bank ────────────────────────────────────────────────── */
  const QUIZ_BANK = [
    { q: "How many months are in a year?",              opts: ["10","11","12","13"],               ans: 2 },
    { q: "What do we call a place full of books?",      opts: ["Hospital","Library","Market","Park"],  ans: 1 },
    { q: "How many days are in a week?",                opts: ["5","6","7","8"],                   ans: 2 },
    { q: "What color do you get mixing blue and yellow?", opts: ["Red","Green","Purple","Orange"], ans: 1 },
    { q: "How many legs does a spider have?",           opts: ["4","6","8","10"],                  ans: 2 },
    { q: "What planet do we live on?",                  opts: ["Mars","Venus","Jupiter","Earth"],  ans: 3 },
    { q: "What is 5 × 5?",                             opts: ["20","25","30","35"],               ans: 1 },
    { q: "How many sides does a triangle have?",        opts: ["2","3","4","5"],                   ans: 1 },
    { q: "What do caterpillars turn into?",             opts: ["Frogs","Bees","Butterflies","Birds"], ans: 2 },
    { q: "What is the biggest ocean on Earth?",         opts: ["Atlantic","Indian","Arctic","Pacific"], ans: 3 },
    { q: "How many letters are in the alphabet?",       opts: ["24","25","26","27"],               ans: 2 },
    { q: "Which animal is the 'King of the Jungle'?",   opts: ["Tiger","Elephant","Lion","Bear"],  ans: 2 },
    { q: "What is the opposite of 'hot'?",              opts: ["Warm","Cold","Cool","Mild"],       ans: 1 },
    { q: "Who writes a book?",                          opts: ["Reader","Author","Printer","Editor"], ans: 1 },
    { q: "How many colors are in a rainbow?",           opts: ["5","6","7","8"],                   ans: 2 },
    { q: "What is the capital of the Philippines?",     opts: ["Cebu","Davao","Manila","Quezon"],  ans: 2 },
    { q: "How many minutes are in one hour?",           opts: ["30","45","60","90"],               ans: 2 },
    { q: "What do plants need to grow?",                opts: ["Darkness","Sunlight","Snow","Sand"], ans: 1 },
    { q: "How many senses do humans have?",             opts: ["3","4","5","6"],                   ans: 2 },
    { q: "What is 2 + 2 + 2?",                         opts: ["4","5","6","7"],                   ans: 2 },
    { q: "Which animal gives us milk?",                 opts: ["Dog","Cow","Horse","Cat"],         ans: 1 },
    { q: "How many seasons are there in a year?",       opts: ["2","3","4","5"],                   ans: 2 },
    { q: "Which fruit is red and grows on a tree?",     opts: ["Banana","Mango","Apple","Grape"],  ans: 2 },
    { q: "What do we use to read a book?",              opts: ["Ears","Nose","Eyes","Hands"],      ans: 2 },
    { q: "What is the smallest planet in our solar system?", opts: ["Mars","Mercury","Pluto","Neptune"], ans: 1 },
  ];

  /* ── Achievements ─────────────────────────────────────────────── */
  const ACHIEVEMENTS = [
    { at: 1,  icon: '⭐', title: 'First Star!',   name: 'Curious Reader'   },
    { at: 5,  icon: '🏆', title: 'Achievement!',  name: 'Bookworm'         },
    { at: 10, icon: '🎓', title: 'Achievement!',  name: 'Library Star'     },
    { at: 20, icon: '🦸', title: 'Achievement!',  name: 'Knowledge Hero'   },
    { at: 50, icon: '👑', title: 'Super Reader!', name: 'Champion Scholar' },
  ];

  /* ── Encouragement messages ──────────────────────────────────── */
  const CORRECT_MSGS = [
    "Fantastic! 🎉",     "You're so smart! 🌟",   "Awesome answer! 🚀",
    "Keep it up! 💪",    "Brilliant! 🧠",          "You rock! 🎸",
    "Amazing! ✨",       "Great job! 👏",          "Perfect! 🎯",
    "Superstar! ⭐",     "Incredible! 🌈",         "Well done! 🥳",
  ];
  const WRONG_MSGS = [
    "Almost! Try again next time 😊",
    "Good try! You'll get it! 💙",
    "Don't give up! You're learning! 🌱",
    "Keep reading to find out! 📚",
    "Every mistake helps us learn! 🌟",
    "You're doing great! Try once more! 💪",
  ];

  /* ── Persistent state ─────────────────────────────────────────── */
  let stars          = parseInt(localStorage.getItem('lms_child_stars')     || '0');
  let lastMilestone  = parseInt(localStorage.getItem('lms_child_milestone') || '0');
  let shownIndices   = JSON.parse(localStorage.getItem('lms_child_shown')   || '[]');

  /* ── Runtime state ────────────────────────────────────────────── */
  let actionCount  = 0;
  let quizVisible  = false;
  let quizTimer    = null;
  let currentQ     = null;
  let answered     = false;
  let initialized  = false;

  /* ── Helpers ──────────────────────────────────────────────────── */
  function saveState() {
    localStorage.setItem('lms_child_stars',     stars);
    localStorage.setItem('lms_child_milestone', lastMilestone);
    localStorage.setItem('lms_child_shown',     JSON.stringify(shownIndices.slice(-60)));
  }

  function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

  function pickQuestion() {
    const unseen = QUIZ_BANK.map((_,i) => i).filter(i => !shownIndices.includes(i));
    const pool   = unseen.length ? unseen : QUIZ_BANK.map((_,i) => i);
    const idx    = pick(pool);
    shownIndices.push(idx);
    return { ...QUIZ_BANK[idx], idx };
  }

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
  }

  /* ── Star counter in sidebar ──────────────────────────────────── */
  function injectStarCounter() {
    const userInfo = document.querySelector('.sidebar-user-info');
    if (!userInfo || userInfo.querySelector('.stars-counter')) return;
    const el = document.createElement('div');
    el.className = 'stars-counter';
    el.id = 'child-stars-counter';
    el.innerHTML = `<span>⭐</span><span id="lms-star-count">${stars}</span> Stars`;
    userInfo.appendChild(el);
  }

  function updateStarDisplay() {
    const el = document.getElementById('lms-star-count');
    if (el) { el.textContent = stars; el.classList.add('star-bump'); setTimeout(() => el.classList.remove('star-bump'), 300); }
  }

  /* ── Achievement popup ────────────────────────────────────────── */
  function checkAchievements() {
    for (const ach of ACHIEVEMENTS) {
      if (stars >= ach.at && lastMilestone < ach.at) {
        lastMilestone = ach.at;
        saveState();
        showAchievement(ach);
        return;
      }
    }
  }

  function showAchievement(ach) {
    const el = document.createElement('div');
    el.className = 'achievement-popup';
    el.innerHTML =
      `<div class="achievement-icon">${ach.icon}</div>` +
      `<div><div class="achievement-title">${esc(ach.title)}</div><div class="achievement-name">${esc(ach.name)}</div></div>`;
    document.body.appendChild(el);
    setTimeout(() => {
      el.style.transition = 'opacity .4s ease, transform .4s ease';
      el.style.opacity    = '0';
      el.style.transform  = 'translateX(40px) scale(.9)';
      setTimeout(() => el.remove(), 420);
    }, 3600);
  }

  /* ── Star burst animation ─────────────────────────────────────── */
  function spawnStarBurst(x, y) {
    const emojis = ['⭐','✨','🌟','💫'];
    for (let i = 0; i < 6; i++) {
      setTimeout(() => {
        const el  = document.createElement('div');
        el.className  = 'star-burst';
        el.textContent = pick(emojis);
        el.style.left  = (x + (Math.random() - .5) * 80) + 'px';
        el.style.top   = y + 'px';
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 1700);
      }, i * 90);
    }
  }

  /* ── Quiz popup ───────────────────────────────────────────────── */
  function showQuiz() {
    if (quizVisible) return;
    // Don't show while a modal is open
    if (document.querySelector('.modal.show')) { scheduleNextQuiz(); return; }

    quizVisible = true;
    currentQ    = pickQuestion();
    answered    = false;

    const optsHtml = currentQ.opts.map((opt, i) =>
      `<button class="quiz-option" data-idx="${i}" onclick="window.__quizAnswer(${i},this)">${esc(opt)}</button>`
    ).join('');

    const bubble = document.createElement('div');
    bubble.className = 'quiz-bubble';
    bubble.id = 'lms-quiz-bubble';
    bubble.innerHTML =
      `<div class="quiz-card">` +
        `<div class="quiz-card-header">` +
          `<div class="quiz-title">📚 Quick Quiz!</div>` +
          `<button class="quiz-close" onclick="window.__quizClose()" title="Dismiss">✕</button>` +
        `</div>` +
        `<div class="quiz-card-body">` +
          `<div class="quiz-question">${esc(currentQ.q)}</div>` +
          `<div class="quiz-options">${optsHtml}</div>` +
          `<div class="quiz-result" id="lms-quiz-result"></div>` +
        `</div>` +
      `</div>`;

    document.body.appendChild(bubble);
    saveState();
  }

  function closeQuiz() {
    const el = document.getElementById('lms-quiz-bubble');
    if (el) {
      el.style.animation = 'none';
      el.style.transition = 'opacity .25s ease, transform .25s ease';
      el.style.opacity    = '0';
      el.style.transform  = 'translateY(16px) scale(.94)';
      setTimeout(() => el.remove(), 260);
    }
    quizVisible = false;
    scheduleNextQuiz();
  }

  function answerQuiz(chosenIdx, btn) {
    if (answered) return;
    answered = true;

    const opts    = btn.closest('.quiz-options').querySelectorAll('.quiz-option');
    const correct = currentQ.ans;
    const isRight = chosenIdx === correct;

    opts.forEach((b, i) => {
      b.disabled = true;
      if (i === correct) b.classList.add('correct');
    });
    if (!isRight) btn.classList.add('wrong');

    if (isRight) {
      stars++;
      updateStarDisplay();
      checkAchievements();
      saveState();
      const r = btn.getBoundingClientRect();
      spawnStarBurst(r.left + r.width / 2, r.top);
    }

    const result = document.getElementById('lms-quiz-result');
    if (result) {
      result.style.display = 'block';
      result.innerHTML =
        `<div class="quiz-result-icon">${isRight ? '🎉' : '💙'}</div>` +
        `<div class="quiz-result-text">${esc(isRight ? pick(CORRECT_MSGS) : pick(WRONG_MSGS))}</div>` +
        `<div class="quiz-result-sub">${isRight ? '+1 ⭐ Star earned!' : 'Keep reading and learning!'}</div>` +
        `<button class="quiz-next-btn" onclick="window.__quizClose()">` +
          (isRight ? 'Collect my star! ⭐' : 'Got it! 👍') +
        `</button>`;
    }
  }

  /* ── Scheduling ───────────────────────────────────────────────── */
  function scheduleNextQuiz() {
    clearTimeout(quizTimer);
    quizTimer = setTimeout(showQuiz, 90 * 1000); // 90s idle → show quiz
  }

  function onUserAction() {
    actionCount++;
    if (actionCount > 0 && actionCount % 5 === 0 && !quizVisible) {
      clearTimeout(quizTimer);
      quizTimer = setTimeout(showQuiz, 4000); // slight delay after action burst
    }
  }

  /* ── Welcome message customisation ──────────────────────────────
     Replaces the generic welcome banner text with something child-friendly.
  ─────────────────────────────────────────────────────────────── */
  function customiseWelcomeBanner() {
    const titleEl = document.querySelector('.welcome-title');
    const subEl   = document.querySelector('.welcome-sub');
    if (!titleEl || titleEl.dataset.childCustomised) return;

    const name = (window.currentUser && window.currentUser.full_name)
      ? window.currentUser.full_name.split(' ')[0]
      : 'Reader';

    titleEl.textContent = `Hi ${name}! 👋 Welcome to the Library`;
    titleEl.dataset.childCustomised = '1';
    if (subEl) subEl.textContent = 'Explore books, learn something new, and earn stars! 🌟';
  }

  /* ── Init ─────────────────────────────────────────────────────── */
  function init() {
    if (initialized) return;
    initialized = true;

    // Expose globals for inline onclick handlers
    window.__quizClose  = closeQuiz;
    window.__quizAnswer = answerQuiz;

    // Inject star counter
    function tryInject() {
      if (document.querySelector('.sidebar-user-info')) {
        injectStarCounter();
        customiseWelcomeBanner();
      } else {
        setTimeout(tryInject, 300);
      }
    }
    tryInject();

    // Re-inject/refresh on tab changes
    document.addEventListener('tabChanged', () => {
      setTimeout(() => {
        injectStarCounter();
        customiseWelcomeBanner();
        clearTimeout(quizTimer);
        quizTimer = setTimeout(showQuiz, 120 * 1000);
      }, 200);
    });

    // Track user interaction (clicks outside quiz)
    document.addEventListener('click', e => {
      if (!e.target.closest('.quiz-bubble')) onUserAction();
    });

    // First quiz: 2 minutes after load
    quizTimer = setTimeout(showQuiz, 2 * 60 * 1000);
  }

  // Run after DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
