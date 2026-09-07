/* ============================================================
   НЕЧАЙ — скрипты сайта
   ============================================================ */
(function () {
  'use strict';

  /* ---------- год в подвале ---------- */
  var yearEl = document.getElementById('year');
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ---------- тень у шапки при скролле ---------- */
  var header = document.getElementById('header');
  function onScroll() {
    header.classList.toggle('is-scrolled', window.scrollY > 12);
  }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  /* ---------- мобильное меню ---------- */
  var burger = document.getElementById('burger');
  var nav = document.getElementById('nav');
  burger.addEventListener('click', function () {
    var open = nav.classList.toggle('is-open');
    burger.classList.toggle('is-open', open);
    burger.setAttribute('aria-expanded', String(open));
  });
  nav.addEventListener('click', function (e) {
    if (e.target.tagName === 'A') {
      nav.classList.remove('is-open');
      burger.classList.remove('is-open');
      burger.setAttribute('aria-expanded', 'false');
    }
  });

  /* ---------- фирменные подложки вместо отсутствующих фото ----------
     Пока файла нет — рисуем тёплую подложку с линейной иллюстрацией.
     Как только фото положат в assets/img, подложка исчезнет сама.     */
  var ORNAMENTS = [
    '<path d="M8 46h48"/><path d="M13 46a19 19 0 0 1 38 0"/><path d="M32 27v-5"/><circle cx="32" cy="19" r="2.6"/>',
    '<path d="M32 55V17"/><path d="M32 35c0-7 5-12 12-13 0 7-5 12-12 13Z"/><path d="M32 45c0-6-4-10-10-11 0 6 4 10 10 11Z"/>',
    '<path d="M20 12h24l-2 14a10 10 0 0 1-20 0L20 12Z"/><path d="M32 36v14"/><path d="M23 52h18"/>',
    '<path d="M18 24h28l-3 26a4 4 0 0 1-4 4H25a4 4 0 0 1-4-4L18 24Z"/><path d="M22 24c0-6 4-10 10-10s10 4 10 10"/><path d="M32 14V9"/>',
    '<path d="M14 9v13"/><path d="M20 9v13"/><path d="M26 9v13"/><path d="M14 22h12c0 4-3 7-6 7s-6-3-6-7Z"/><path d="M20 29v26"/><path d="M46 9c4 8 4 19 0 27"/><path d="M46 36v19"/>',
    '<path d="M14 24h28v10a14 14 0 0 1-28 0V24Z"/><path d="M42 27h4a5 5 0 0 1 0 10h-4"/><path d="M10 53h36"/>',
    '<circle cx="26" cy="35" r="5"/><circle cx="38" cy="35" r="5"/><circle cx="32" cy="45" r="5"/><path d="M32 30V17"/><path d="M32 19c4-4 9-4 12-2-2 4-7 6-12 2Z"/>',
    '<rect x="16" y="26" width="32" height="9" rx="4.5"/><rect x="16" y="38" width="32" height="9" rx="4.5"/><path d="M20 21c0-4 5-7 12-7s12 3 12 7"/>'
  ];

  var phCount = 0;

  function makeArt(index) {
    return '<span class="ph-box__art" aria-hidden="true">' +
             '<svg viewBox="0 0 64 64" fill="none" stroke="currentColor" ' +
             'stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' +
             ORNAMENTS[index % ORNAMENTS.length] +
             '</svg>' +
           '</span>';
  }

  function handleMissing(img) {
    if (img.dataset.phDone) return;      // событие error может прийти повторно
    img.dataset.phDone = '1';
    var kind = img.getAttribute('data-fallback');

    // логотип — показываем текстовое начертание вместо картинки
    if (kind === 'logo') {
      img.hidden = true;
      var text = img.parentNode.querySelector('.logo__text');
      if (text) text.hidden = false;
      return;
    }

    var box = document.createElement('div');
    box.className = 'ph-box ph-box--t' + (phCount % 6 + 1);
    box.innerHTML = makeArt(phCount);
    phCount++;
    img.parentNode.insertBefore(box, img);
    img.remove();
  }

  Array.prototype.forEach.call(document.querySelectorAll('img[data-fallback]'), function (img) {
    img.addEventListener('error', function () { handleMissing(img); });
    if (img.complete && img.naturalWidth === 0) handleMissing(img);
  });

  /* ---------- появление секций при скролле ---------- */
  var revealTargets = document.querySelectorAll(
    '.section-head, .feature, .format-card, .about__media, .about__text, ' +
    '.menu-card, .menu__cta, .step, .pf, .review, .contacts__info, .form, ' +
    '.social-card, .stat, .social-cta__inner'
  );
  Array.prototype.forEach.call(revealTargets, function (el) { el.classList.add('reveal'); });

  if ('IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry, i) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        setTimeout(function () { el.classList.add('is-visible'); }, Math.min(i * 70, 350));
        io.unobserve(el);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    Array.prototype.forEach.call(revealTargets, function (el) { io.observe(el); });
  } else {
    Array.prototype.forEach.call(revealTargets, function (el) { el.classList.add('is-visible'); });
  }

  /* ---------- лайтбокс портфолио ---------- */
  var lightbox = document.getElementById('lightbox');
  if (lightbox) {
  var lbImg = lightbox.querySelector('img');
  var links = [];
  var current = 0;

  function refreshLinks() {
    links = Array.prototype.filter.call(
      document.querySelectorAll('[data-lightbox]'),
      function (a) { return a.querySelector('img'); }   // без фото — не открываем
    );
  }
  refreshLinks();

  function openLightbox(index) {
    current = (index + links.length) % links.length;
    lbImg.src = links[current].getAttribute('href');
    lbImg.alt = links[current].querySelector('img').alt || '';
    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeLightbox() {
    lightbox.hidden = true;
    lbImg.src = '';
    document.body.style.overflow = '';
  }

  document.addEventListener('click', function (e) {
    var link = e.target.closest && e.target.closest('[data-lightbox]');
    if (!link) return;
    refreshLinks();
    var i = links.indexOf(link);
    if (i === -1) return;              // фото ещё не загружено — оставляем ссылку как есть
    e.preventDefault();
    openLightbox(i);
  });

  lightbox.querySelector('.lightbox__close').addEventListener('click', closeLightbox);
  lightbox.querySelector('.lightbox__nav--prev').addEventListener('click', function () { openLightbox(current - 1); });
  lightbox.querySelector('.lightbox__nav--next').addEventListener('click', function () { openLightbox(current + 1); });
  lightbox.addEventListener('click', function (e) { if (e.target === lightbox) closeLightbox(); });
  document.addEventListener('keydown', function (e) {
    if (lightbox.hidden) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') openLightbox(current - 1);
    if (e.key === 'ArrowRight') openLightbox(current + 1);
  });
  }

  /* ---------- форма заявки ---------- */
  var WHATSAPP_NUMBER = '79624908483';

  var form = document.getElementById('orderForm');
  var status = document.getElementById('formStatus');

  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    status.textContent = '';

    var name = form.name.value.trim();
    var phone = form.phone.value.trim();
    var invalid = false;

    [['name', name], ['phone', phone]].forEach(function (pair) {
      var field = form[pair[0]];
      var bad = pair[1].length < 2;
      field.classList.toggle('is-error', bad);
      if (bad) invalid = true;
    });

    if (invalid) {
      status.textContent = 'Заполните имя и телефон — остальное уточним сами.';
      return;
    }

    var lines = [
      'Заявка с сайта «Нечай»',
      'Имя: ' + name,
      'Телефон: ' + phone,
      'Формат: ' + form.format.value
    ];
    if (form.guests.value) lines.push('Гостей: ' + form.guests.value);
    if (form.date.value) lines.push('Дата: ' + form.date.value);
    if (form.comment.value.trim()) lines.push('Комментарий: ' + form.comment.value.trim());

    var url = 'https://wa.me/' + WHATSAPP_NUMBER + '?text=' + encodeURIComponent(lines.join('\n'));
    window.open(url, '_blank', 'noopener');

    status.textContent = 'Открыли WhatsApp с вашей заявкой — осталось нажать «Отправить».';
    form.reset();
  });

  /* ---------- счётчики подписчиков ----------
     Число берётся из атрибута data-count, необязательный data-suffix
     дописывается справа (например «%»). Разряды разделяются пробелом.  */
  var reduced = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function groupDigits(n) {
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0');
  }

  function runCounter(el) {
    if (el.dataset.counted) return;
    el.dataset.counted = '1';

    var target = parseInt(el.getAttribute('data-count'), 10) || 0;
    var suffix = el.getAttribute('data-suffix') || '';
    var bar = el.parentNode.querySelector('.counter-bar i');

    if (reduced || !window.requestAnimationFrame) {
      el.textContent = groupDigits(target) + suffix;
      if (bar) bar.style.width = '100%';
      return;
    }

    var duration = 1700;
    var started = null;
    var done = false;

    function finish() {
      if (done) return;
      done = true;
      el.textContent = groupDigits(target) + suffix;
      if (bar) bar.style.width = '100%';
    }

    function step(now) {
      if (done) return;
      if (started === null) started = now;
      var p = Math.min((now - started) / duration, 1);
      if (p >= 1) { finish(); return; }
      var eased = 1 - Math.pow(1 - p, 3);          // плавное торможение к концу
      el.textContent = groupDigits(Math.round(target * eased)) + suffix;
      if (bar) bar.style.width = (eased * 100).toFixed(1) + '%';
      requestAnimationFrame(step);
    }

    // если вкладку свернули на середине, requestAnimationFrame встаёт —
    // страховка добивает счётчик до конечного числа
    setTimeout(finish, duration + 500);
    requestAnimationFrame(step);
  }

  var counters = document.querySelectorAll('.counter[data-count]');
  if (counters.length) {
    if ('IntersectionObserver' in window) {
      var cio = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          runCounter(entry.target);
          cio.unobserve(entry.target);
        });
      }, { threshold: 0.4 });
      Array.prototype.forEach.call(counters, function (el) { cio.observe(el); });
    } else {
      Array.prototype.forEach.call(counters, runCounter);
    }
  }

  /* ---------- точки под каруселью форматов (только на телефоне) ---------- */
  var fGrid = document.querySelector('.formats__grid');
  var fDots = document.getElementById('formatsDots');

  if (fGrid && fDots) {
    var dotList = [];

    function columnWidth() {
      var first = fGrid.children[0];
      if (!first) return 0;
      var gap = parseFloat(getComputedStyle(fGrid).columnGap) || 0;
      return first.getBoundingClientRect().width + gap;
    }

    function syncActive() {
      var w = columnWidth();
      if (!w || !dotList.length) return;
      var i = Math.round(fGrid.scrollLeft / w);
      if (i > dotList.length - 1) i = dotList.length - 1;
      dotList.forEach(function (d, k) {
        d.setAttribute('aria-current', k === i ? 'true' : 'false');
      });
    }

    function buildDots() {
      // карусель включается только в мобильной раскладке
      var scrolls = fGrid.scrollWidth > fGrid.clientWidth + 4;
      var need = scrolls ? Math.ceil(fGrid.children.length / 2) : 0;

      if (need === dotList.length) { syncActive(); return; }

      fDots.textContent = '';
      dotList = [];
      for (var i = 0; i < need; i++) {
        (function (index) {
          var b = document.createElement('button');
          b.type = 'button';
          b.setAttribute('aria-label', 'Показать формат ' + (index * 2 + 1));
          b.addEventListener('click', function () {
            fGrid.scrollTo({ left: columnWidth() * index, behavior: 'smooth' });
          });
          fDots.appendChild(b);
          dotList.push(b);
        })(i);
      }
      syncActive();
    }

    buildDots();
    fGrid.addEventListener('scroll', syncActive, { passive: true });
    window.addEventListener('resize', buildDots);
    window.addEventListener('load', buildDots);
  }
})();
