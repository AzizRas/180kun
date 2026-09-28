/* LEVEL 180 — клиент.
   Без сборки и без фреймворков: один файл, который читается сверху вниз.
   Экран = функция, возвращающая DOM-узел. Состояние — один объект. */

(function () {
  'use strict';

  var TG = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;

  var state = {
    lang: (window.L180 && window.L180.lang) || 'ru',
    strings: {},
    user: null,
    token: null,
    flow: {},          // временные данные текущего сценария
    schema: null,      // структура анкеты с сервера
    answers: {},       // ответы онбординга
    qIndex: 0,
    onboarding: null,
    today: null,
    plan: null,
    checkin: null,
    progress: null,
    squad: null,       // мой сквад или место в очереди
    leader: null,      // панель лидера
    coach: null,       // согласие и доступность тренера
    wear: null,        // данные браслета
    week: null,        // недельный обзор
    billing: null,     // сезон и оплата
    payment: null,     // текущий перевод
    feed: null,        // лента сквада
    calls: null        // созвоны сквада
  };

  // ---------- вспомогательное ----------

  /**
   * Запасные строки оболочки. Настоящие приходят с сервера (modules/Web/lang);
   * эти — на случай, если файл переводов не доехал до сервера при выкладке:
   * человек должен видеть слова, а не ключи вида ui.greet.day.
   */
  var FALLBACK = {
    ru: {
      "ui.greet.morning": "Доброе утро",
      "ui.greet.day": "Добрый день",
      "ui.greet.evening": "Добрый вечер",
      "ui.greet.night": "Доброй ночи",
      "ui.nav.home": "Главная",
      "ui.nav.squad": "Сквад",
      "ui.nav.feed": "Лента",
      "ui.nav.me": "Профиль",
      "ui.w.ring": "в скваде",
      "ui.w.days": "дней, чтобы изменить тело — вместе",
      "ui.w.squad": "Сквад из 6 человек",
      "ui.w.coach": "ИИ-тренер",
      "ui.w.return": "Можно сорваться — важно вернуться",
      "ui.season_day": "День сезона",
      "ui.chapter_n": "глава {n}",
      "ui.of_season": "сезона",
      "ui.weekdays": "Вс,Пн,Вт,Ср,Чт,Пт,Сб",
      "ui.change": "Изменить",
      "ui.tile.week": "Неделя",
      "ui.tile.streak": "Серия",
      "ui.tile.shields": "Щиты",
      "ui.tile.event": "Событие",
      "ui.chat": "Чат",
      "ui.retry": "Повторить"
    },
    uz: {
      "ui.greet.morning": "Xayrli tong",
      "ui.greet.day": "Xayrli kun",
      "ui.greet.evening": "Xayrli kech",
      "ui.greet.night": "Xayrli tun",
      "ui.nav.home": "Bosh sahifa",
      "ui.nav.squad": "Skvad",
      "ui.nav.feed": "Lenta",
      "ui.nav.me": "Profil",
      "ui.w.ring": "skvadda",
      "ui.w.days": "kun ichida tanani o‘zgartirish — birgalikda",
      "ui.w.squad": "6 kishilik skvad",
      "ui.w.coach": "SI-murabbiy",
      "ui.w.return": "Yiqilish mumkin — muhimi qaytish",
      "ui.season_day": "Mavsum kuni",
      "ui.chapter_n": "{n}-bob",
      "ui.of_season": "mavsum",
      "ui.weekdays": "Ya,Du,Se,Ch,Pa,Ju,Sh",
      "ui.change": "O‘zgartirish",
      "ui.tile.week": "Hafta",
      "ui.tile.streak": "Ketma-ket",
      "ui.tile.shields": "Qalqonlar",
      "ui.tile.event": "Hodisa",
      "ui.chat": "Chat",
      "ui.retry": "Qayta urinish"
    }
  };

  function t(key, vars) {
    var s = state.strings[key] || (FALLBACK[state.lang] || {})[key] || FALLBACK.ru[key] || key;
    if (vars) {
      Object.keys(vars).forEach(function (k) {
        s = s.split('{' + k + '}').join(vars[k]);
      });
    }
    return s;
  }

  function el(tag, attrs, children) {
    var node = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'class') node.className = attrs[k];
      else if (k === 'text') node.textContent = attrs[k];
      else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] === true) node.setAttribute(k, '');
      else if (attrs[k] !== false && attrs[k] != null) node.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) {
      if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return node;
  }

  function storage(key, value) {
    try {
      if (value === undefined) return localStorage.getItem(key);
      if (value === null) localStorage.removeItem(key);
      else localStorage.setItem(key, value);
    } catch (e) { /* приватный режим — живём без хранилища */ }
    return null;
  }

  function api(method, path, body) {
    var headers = { 'Content-Type': 'application/json' };
    if (state.token) headers['X-Session-Token'] = state.token;

    return fetch(path, {
      method: method,
      headers: headers,
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        data.__status = res.status;
        return data;
      });
    }).catch(function () {
      return { ok: false, __status: 0, message: t('ui.network_error') };
    });
  }

  // ---------- иконки и мелкие детали ----------

  /** Линейные иконки 24×24 в одном стиле. Свои, чтобы не тянуть библиотеку. */
  var ICONS = {
    home: '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    users: '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.2c2.9.3 5 2.4 5 5.8"/>',
    image: '<rect x="3" y="4" width="18" height="16" rx="4"/><circle cx="9" cy="10" r="1.8"/><path d="m21 16-5-5-9 9"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    flame: '<path d="M12 3c1 3.5 5 5.5 5 10a5 5 0 0 1-10 0c0-2.2 1.2-3.8 2.5-5 .3 2 1.3 3 2.5 3.2C12 8.5 11 6 12 3z"/>',
    shield: '<path d="M12 3 19 6v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
    bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
    video: '<rect x="3" y="6" width="13" height="12" rx="3"/><path d="m16 10.5 5-3v9l-5-3"/>',
    chat: '<path d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-8l-5 4v-4H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/>',
    chevron: '<path d="m9 6 6 6-6 6"/>',
    target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
    scale: '<rect x="3" y="3" width="18" height="18" rx="5"/><path d="M8 10a5 5 0 0 1 8 0"/><path d="m12 10 1.5-2"/>',
    sparkle: '<path d="M12 3c.6 4.2 2.8 6.4 7 7-4.2.6-6.4 2.8-7 7-.6-4.2-2.8-6.4-7-7 4.2-.6 6.4-2.8 7-7z"/>',
    card: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M3 10h18"/>',
    download: '<path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/>',
    settings: '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
    logout: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/>',
    watch: '<rect x="6" y="6" width="12" height="12" rx="3"/><path d="M9 6V3h6v3M9 18v3h6v-3"/>',
    calendar: '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    steps: '<path d="M8 3c1.7 0 2.5 2 2.5 4.5S9.5 12 8 12s-2.5-2-2.5-4.5S6.3 3 8 3zM6 15h4v2a2 2 0 0 1-4 0zM16 7c1.7 0 2.5 2 2.5 4.5S17.5 16 16 16s-2.5-2-2.5-4.5S14.3 7 16 7zM14 19h4v.5a2 2 0 0 1-4 0z"/>',
    moon: '<path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5z"/>',
    heart: '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z"/>'
  };

  function icon(name) {
    var s = el('span', { class: 'ico', 'aria-hidden': 'true' });
    s.innerHTML = '<svg viewBox="0 0 24 24">' + (ICONS[name] || '') + '</svg>';
    return s;
  }
  function bubble(name) { return el('span', { class: 'ico-bubble' }, [icon(name)]); }
  function initial(name) { var s = String(name || '').replace(/^#/, '').trim(); return s ? s.charAt(0).toUpperCase() : '•'; }
  function face(name, idx) { return el('span', { class: 'face face-' + ((idx || 0) % 7), 'aria-hidden': 'true' }, [initial(name)]); }

  /** Кольцо прогресса: процент, крупная подпись в центре, мелкая под ней. */
  function ring(pct, big, small) {
    var C = 2 * Math.PI * 44;
    var k = Math.max(0, Math.min(1, pct / 100));
    var wrap = el('div', { class: 'ring', role: 'img', 'aria-label': big + (small ? ' ' + small : '') });
    wrap.innerHTML = '<svg viewBox="0 0 100 100"><circle class="rt" cx="50" cy="50" r="44"/>'
      + '<circle class="rv" cx="50" cy="50" r="44" stroke-dasharray="' + C.toFixed(1) + '" stroke-dashoffset="' + (C * (1 - k)).toFixed(1) + '"/></svg>';
    wrap.appendChild(el('div', { class: 'ring-in' }, [el('b', { text: big }), small ? el('small', { text: small }) : null]));
    return wrap;
  }

  /** Плитка бенто: подпись с иконкой, крупное число, пояснение. */
  function tile(cls, title, ic, value, unit, sub, extra, onClick) {
    return el(onClick ? 'button' : 'div', { class: 'tile ' + (cls || ''), type: onClick ? 'button' : null, onclick: onClick || null }, [
      el('div', { class: 'tile-top' }, [el('span', { text: title }), bubble(ic)]),
      el('div', { class: 'tile-v' }, [String(value), unit ? el('span', { class: 'unit', text: unit }) : null]),
      extra || null,
      sub ? el('div', { class: 'tile-s', text: sub }) : null
    ]);
  }

  /** Тема: у Telegram своя (светлая/тёмная), в браузере — из настроек телефона. */
  function applyTheme() {
    var dark = TG && TG.colorScheme ? TG.colorScheme === 'dark'
      : !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
    var bg = dark ? '#0D0E0B' : '#F2F3EE';
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', bg);
    try {
      if (TG && TG.setHeaderColor) TG.setHeaderColor(bg);
      if (TG && TG.setBackgroundColor) TG.setBackgroundColor(bg);
      if (TG && TG.setBottomBarColor) TG.setBottomBarColor(bg);
    } catch (e) { /* старый клиент Telegram — живём с его цветом */ }
  }

  // ---------- сборка экрана ----------

  var host = document.getElementById('app');

  function render(node) {
    host.textContent = '';
    host.appendChild(node);
    host.classList.toggle('with-nav', !!(node.querySelector && node.querySelector('.navbar')));
    if (!TG) {
      var first = host.querySelector('input');
      if (first) first.focus();
    }
    window.scrollTo(0, 0);
  }

  function header(subtitle) {
    return el('div', { class: 'top' }, [
      el('div', { class: 'brand' }, [
        el('span', { class: 'mark', text: '180' }),
        el('div', { class: 'brand-sub' }, subtitle
          ? [el('small', { text: 'LEVEL 180' }), el('b', { text: subtitle })]
          : [el('b', { text: 'LEVEL 180' })])
      ]),
      langSwitch()
    ]);
  }

  /** Шапка главной: аватар с буквой имени и приветствие по времени суток. */
  function greetHeader() {
    var h = new Date().getHours();
    var part = h < 5 ? 'night' : h < 12 ? 'morning' : h < 18 ? 'day' : 'evening';
    var name = (state.user && state.user.name) || '';
    return el('div', { class: 'top' }, [
      el('div', { class: 'brand' }, [
        el('span', { class: 'avatar', text: initial(name || 'L') }),
        el('div', { class: 'brand-sub' }, [el('small', { text: t('ui.greet.' + part) }), el('b', { text: name || 'LEVEL 180' })])
      ]),
      langSwitch()
    ]);
  }

  /**
   * Нижняя панель главных экранов. В центре — круглая кнопка чек-ина:
   * это главное действие дня, до него один палец.
   */
  function navbar(active) {
    var s = state.squad;
    var hasSquad = !!(s && s.squad);
    var c = state.checkin || {};
    function item(key, ic, label, fn) {
      return el('button', { type: 'button', class: active === key ? 'on' : null, 'aria-current': active === key ? 'page' : null, onclick: function () { fn(); } }, [
        icon(ic), el('span', { text: label })
      ]);
    }
    var done = !!c.recorded;
    var fab = el('button', {
      type: 'button', 'aria-label': t('checkin.save'),
      onclick: function () { if (state.checkin) go(screenCheckin); else loadHome(); }
    }, [el('span', { class: 'fab' + (done ? ' fab-done' : '') }, [icon(done ? 'check' : 'plus')])]);

    var fourth = hasModule('feed.open') ? item('feed', 'image', t('ui.nav.feed'), openFeed)
      : hasModule('calls.open') ? item('calls', 'video', t('calls.open'), openCalls)
      : item('week', 'sparkle', t('coach.title'), openWeek);

    return el('nav', { class: 'navbar', 'aria-label': 'LEVEL 180' }, [
      item('home', 'home', t('ui.nav.home'), function () { loadHome(); }),
      item('squad', 'users', t('ui.nav.squad'), function () { if (hasSquad) go(screenSquad); else loadHome(); }),
      fab,
      fourth,
      item('me', 'user', t('ui.nav.me'), function () { go(screenMe); })
    ]);
  }

  function langSwitch() {
    function make(code, label) {
      return el('button', {
        type: 'button',
        'aria-pressed': state.lang === code ? 'true' : 'false',
        onclick: function () { setLang(code); }
      }, [label]);
    }
    return el('div', { class: 'lang', role: 'group' }, [make('ru', 'RU'), make('uz', 'UZ')]);
  }

  function setLang(code) {
    if (code === state.lang) return;
    state.lang = code;
    storage('lang', code);
    document.cookie = 'lang=' + code + ';path=/;max-age=31536000;samesite=lax';
    if (state.user) api('POST', '/api/me/lang', { lang: code });
    loadStrings().then(route);
  }

  function note(kind, text) {
    return el('div', { class: 'note note-' + kind, role: kind === 'error' ? 'alert' : 'status', text: text });
  }

  function field(label, attrs) {
    var id = 'f-' + Math.random().toString(36).slice(2, 8);
    var input = el('input', Object.assign({ id: id }, attrs));
    return { wrap: el('div', { class: 'field' }, [el('label', { for: id, text: label }), input]), input: input };
  }

  function busy(button, on) {
    button.disabled = on;
    button.textContent = on ? t('ui.loading') : button.dataset.label;
  }

  function actionButton(label, cls, onClick) {
    var b = el('button', { type: 'submit', class: 'btn ' + cls, text: label });
    b.dataset.label = label;
    if (onClick) b.addEventListener('click', onClick);
    return b;
  }

  function choice(label, selected, onPick) {
    return el('button', {
      type: 'button',
      class: 'opt' + (selected ? ' opt-on' : ''),
      text: label,
      onclick: onPick
    });
  }

  // ---------- экраны входа ----------

  function screenWelcome() {
    var actions = el('div', { class: 'stack' }, []);

    if (TG && TG.initData) {
      actions.appendChild(actionButton(t('ui.continue_tg'), 'btn-tg', function () { loginWithTelegram(true); }));
    }
    actions.appendChild(actionButton(t('ui.register'), 'btn-primary', function () { go(screenRegister); }));
    actions.appendChild(actionButton(t('ui.enter'), 'btn-ghost', function () { go(screenLogin); }));

    return el('div', { class: 'stack' }, [
      header(),
      el('div', { class: 'welcome-art' }, [
        ring(33, '6', t('ui.w.ring')),
        el('div', { class: 'big', text: '180' }),
        el('div', { class: 'wa-sub', text: t('ui.w.days') }),
        el('div', { class: 'welcome-stats' }, [
          el('span', { class: 'pill', text: t('ui.w.squad') }),
          el('span', { class: 'pill', text: t('ui.w.coach') }),
          el('span', { class: 'pill', text: t('ui.w.return') })
        ])
      ]),
      el('h1', { class: 'welcome-title', text: t('ui.tagline') }),
      el('div', { class: 'spacer' }),
      actions
    ]);
  }

  function screenRegister() {
    var name = field(t('ui.name'), { type: 'text', autocomplete: 'given-name', placeholder: 'Davron' });
    var phone = field(t('ui.phone'), { type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '+998 90 123 45 67' });
    var pass = field(t('ui.password'), { type: 'password', autocomplete: 'new-password', placeholder: '••••••••' });
    var msg = el('div', {});
    var submit = actionButton(t('ui.register'), 'btn-primary');

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/auth/register', {
          name: name.input.value, phone: phone.input.value,
          password: pass.input.value, lang: state.lang
        }).then(function (r) {
          busy(submit, false);
          if (r.ok && r.token) return signedIn(r);
          msg.textContent = '';
          msg.appendChild(note('error', r.message || t('ui.network_error')));
        });
      }
    }, [name.wrap, phone.wrap, pass.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(), el('h2', { text: t('ui.register') }), form,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.have_account'), onclick: function () { go(screenLogin); } })
    ]);
  }

  function screenLogin() {
    var phone = field(t('ui.phone'), { type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '+998 90 123 45 67' });
    var pass = field(t('ui.password'), { type: 'password', autocomplete: 'current-password', placeholder: '••••••••' });
    var msg = el('div', {});
    var submit = actionButton(t('ui.enter'), 'btn-primary');

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/auth/login', { phone: phone.input.value, password: pass.input.value })
          .then(function (r) {
            busy(submit, false);
            if (r.ok && r.token) return signedIn(r);
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
      }
    }, [phone.wrap, pass.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(), el('h2', { text: t('ui.enter') }), form,
      el('button', { class: 'btn btn-quiet', text: t('ui.forgot'), onclick: function () { go(screenForgot); } }),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.no_account'), onclick: function () { go(screenRegister); } })
    ]);
  }

  function screenForgot() {
    var phone = field(t('ui.phone'), { type: 'tel', inputmode: 'tel', placeholder: '+998 90 123 45 67' });
    var msg = el('div', {});
    var submit = actionButton(t('ui.send_code'), 'btn-primary');

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/auth/code/request', { phone: phone.input.value, purpose: 'reset' })
          .then(function (r) {
            busy(submit, false);
            if (r.ok) {
              state.flow = { phone: phone.input.value, debug: r.debug_code || null };
              return go(screenCode);
            }
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
      }
    }, [phone.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(), el('h2', { text: t('ui.forgot') }), form,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { go(screenLogin); } })
    ]);
  }

  function screenCode() {
    var code = field(t('ui.code'), { type: 'text', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '6', class: 'code', placeholder: '••••••' });
    var msg = el('div', {});
    var submit = actionButton(t('ui.enter'), 'btn-primary');

    if (state.flow.debug) msg.appendChild(note('info', 'debug: ' + state.flow.debug));

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/auth/code/verify', { phone: state.flow.phone, purpose: 'reset', code: code.input.value })
          .then(function (r) {
            busy(submit, false);
            if (r.ok && r.ticket) { state.flow.ticket = r.ticket; return go(screenNewPassword); }
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
      }
    }, [code.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(), el('h2', { text: t('ui.code') }),
      el('p', { class: 'muted', text: state.flow.phone || '' }), form,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { go(screenForgot); } })
    ]);
  }

  function screenNewPassword() {
    var pass = field(t('ui.new_password'), { type: 'password', autocomplete: 'new-password', placeholder: '••••••••' });
    var msg = el('div', {});
    var submit = actionButton(t('ui.save'), 'btn-primary');

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/auth/password/reset', {
          phone: state.flow.phone, ticket: state.flow.ticket, password: pass.input.value
        }).then(function (r) {
          busy(submit, false);
          if (r.ok && r.token) return signedIn(r);
          msg.textContent = '';
          msg.appendChild(note('error', r.message || t('ui.network_error')));
        });
      }
    }, [pass.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [header(), el('h2', { text: t('ui.new_password') }), form]);
  }

  // ---------- онбординг ----------

  function progressDots(current, total) {
    var row = el('div', { class: 'dots', 'aria-label': (current + 1) + '/' + total }, []);
    for (var i = 0; i < total; i++) {
      row.appendChild(el('i', { class: i <= current ? 'on' : '' }));
    }
    return row;
  }

  function screenQuestion() {
    var qs = state.schema.questions;
    var q = qs[state.qIndex];
    var body = el('div', { class: 'stack' }, []);

    if (q.type === 'choice') {
      var opts = el('div', { class: 'opts' }, []);
      q.options.forEach(function (o) {
        opts.appendChild(choice(
          t('q.' + q.key + '.' + o),
          String(state.answers[q.key]) === String(o),
          function () { state.answers[q.key] = o; nextQuestion(); }
        ));
      });
      body.appendChild(opts);
    } else if (q.type === 'number') {
      var num = field(t('q.' + q.key), {
        type: 'number', inputmode: 'numeric', min: q.min, max: q.max,
        value: state.answers[q.key] || ''
      });
      body.appendChild(num.wrap);
      body.appendChild(actionButton(t('ui.next'), 'btn-primary', function (e) {
        e.preventDefault();
        state.answers[q.key] = num.input.value;
        nextQuestion();
      }));
    } else if (q.type === 'group') {
      var inputs = [];
      q.fields.forEach(function (f) {
        var fl = field(t('q.' + f.key), {
          type: 'number', inputmode: 'decimal', min: f.min, max: f.max,
          step: f.step || 1, value: state.answers[f.key] || ''
        });
        inputs.push([f.key, fl.input]);
        body.appendChild(fl.wrap);
      });
      body.appendChild(actionButton(t('ui.next'), 'btn-primary', function (e) {
        e.preventDefault();
        inputs.forEach(function (pair) { state.answers[pair[0]] = pair[1].value; });
        nextQuestion();
      }));
    }

    var blocks = [
      header(),
      progressDots(state.qIndex, qs.length),
      el('h2', { text: t('q.' + q.key) }),
      body,
      el('div', { class: 'spacer' })
    ];

    if (state.qIndex > 0) {
      blocks.push(el('button', {
        class: 'btn btn-quiet', text: t('ui.back'),
        onclick: function () { state.qIndex--; go(screenQuestion); }
      }));
    }

    // Модератор не обязан сам проходить сезон, чтобы собирать сквады.
    if (state.qIndex === 0 && isModerator()) {
      blocks.push(el('button', { class: 'btn btn-ghost', text: t('admin.title'), onclick: openAdmin }));
    }

    return el('div', { class: 'stack' }, blocks);
  }

  function nextQuestion() {
    if (state.qIndex < state.schema.questions.length - 1) {
      state.qIndex++;
      return go(screenQuestion);
    }
    submitAnswers();
  }

  function submitAnswers() {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));
    api('POST', '/api/onboarding/answers', state.answers).then(function (r) {
      if (r.ok) return go(screenScreening);
      if (r.error === 'consent_required') { state.answers.consent_health = 0; return go(screenConsent); }
      state.qIndex = 0;
      render(el('div', { class: 'stack' }, [
        header(), note('error', r.message || t('ui.network_error')),
        actionButton(t('ui.back'), 'btn-ghost', function () { go(screenQuestion); })
      ]));
    });
  }

  function screenScreening() {
    var keys = state.schema.screening.parq.concat(state.schema.screening.blockers);
    var picked = {};
    var msg = el('div', {});
    var submit = actionButton(t('ui.save'), 'btn-primary');
    var rows = el('div', { class: 'stack' }, []);

    keys.forEach(function (key) {
      var yes = choice(t('parq.yes'), false, function () { set(key, 1, yes, no); });
      var no = choice(t('parq.no'), false, function () { set(key, 0, no, yes); });
      rows.appendChild(el('div', { class: 'qrow' }, [
        el('p', { text: t('parq.' + key) }),
        el('div', { class: 'opts opts-2' }, [no, yes])
      ]));
    });

    function set(key, value, on, off) {
      picked[key] = value;
      on.classList.add('opt-on');
      off.classList.remove('opt-on');
      submit.disabled = Object.keys(picked).length < keys.length;
    }
    submit.disabled = true;

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/onboarding/screening', { answers: picked }).then(function (r) {
          busy(submit, false);
          if (r.eligible === false) {
            state.flow.reject = r;
            return go(screenRejected);
          }
          if (r.ok) {
            state.flow.needsDoctor = !!r.needs_doctor;
            return refreshOnboarding();
          }
          msg.textContent = '';
          msg.appendChild(note('error', r.message || t('ui.network_error')));
        });
      }
    }, [rows, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(), el('h2', { text: t('parq.title') }),
      el('p', { class: 'muted', text: t('parq.intro') }),
      form
    ]);
  }

  function screenRejected() {
    var r = state.flow.reject || {};
    return el('div', { class: 'stack' }, [
      header(),
      el('div', { class: 'card' }, [
        el('h2', { text: t('onboarding.not_eligible') }),
        el('p', { text: r.message || '' }),
        el('p', { class: 'muted', text: r.advice || t('onboarding.reject_advice') })
      ]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.logout'), onclick: logout })
    ]);
  }

  function screenZeroCycle() {
    var o = state.onboarding || {};
    var done = (o.baseline && o.baseline.days) ? Number(o.baseline.days) : 0;
    var total = state.schema ? state.schema.zero_cycle_days : 7;

    var steps = field(t('zero.steps'), { type: 'number', inputmode: 'numeric', min: 0, max: 60000, placeholder: '4200' });
    var workouts = field(t('zero.workouts'), { type: 'number', inputmode: 'numeric', min: 0, max: 14, value: '0' });
    var sleep = field(t('zero.sleep'), { type: 'number', inputmode: 'decimal', min: 0, max: 14, step: 0.5, placeholder: '7' });
    var msg = el('div', {});
    var submit = actionButton(t('zero.add'), 'btn-primary');

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/onboarding/baseline', {
          steps: steps.input.value,
          workouts: workouts.input.value,
          sleep_min: Math.round((parseFloat(sleep.input.value) || 0) * 60)
        }).then(function (r) {
          busy(submit, false);
          if (!r.ok) {
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
            return;
          }
          if (Number(r.left) <= 0) return completeOnboarding();
          refreshOnboarding();
        });
      }
    }, [steps.wrap, workouts.wrap, sleep.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(),
      el('div', { class: 'card' }, [
        el('h2', { text: t('zero.title') }),
        el('p', { text: t('zero.intro') }),
        el('div', { class: 'track' }, [el('i', { style: 'width:' + Math.round(done / total * 100) + '%' })]),
        el('p', { class: 'muted', style: 'margin:12px 0 0', text: t('zero.day_of', { n: done + 1, total: total }) })
      ]),
      form
    ]);
  }

  function completeOnboarding() {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));
    api('POST', '/api/onboarding/complete', {}).then(function (r) {
      if (r.ok) {
        state.flow.tier = r.tier;
        return go(screenTier);
      }
      refreshOnboarding();
    });
  }

  function screenTier() {
    var tier = state.flow.tier || 'T0';
    return el('div', { class: 'stack' }, [
      header(),
      el('div', { class: 'card center' }, [
        el('p', { class: 'muted', text: t('zero.done') }),
        el('div', { class: 'tier', text: tier }),
        el('h2', { text: t('tier.' + tier) }),
        el('p', { class: 'muted', text: t('tier.explain') })
      ]),
      el('div', { class: 'spacer' }),
      actionButton(t('zero.build_plan'), 'btn-primary', function () { loadHome(); })
    ]);
  }

  // ---------- план и кабинет ----------

  function weekStrip(week) {
    var strip = el('div', { class: 'week', 'aria-label': t('checkin.week') }, []);
    (week.days || []).forEach(function (d) {
      var cls = 'wd';
      if (d.done === 'yes') cls += ' wd-yes';
      else if (d.done === 'partial') cls += ' wd-half';
      else if (d.done === 'no') cls += ' wd-no';
      else if (d.excused) cls += ' wd-ex';
      else if (d.future) cls += ' wd-future';
      strip.appendChild(el('i', { class: cls, title: d.date }));
    });
    return strip;
  }

  /** Лента дней недели: сегодня — чёрная «таблетка», точка — как прошёл день. */
  function daysStrip(week, today) {
    var names = String(t('ui.weekdays')).split(',');   // с воскресенья
    return el('div', { class: 'days', 'aria-label': t('checkin.week') }, (week.days || []).map(function (d) {
      var dt = new Date(d.date + 'T00:00:00');
      var cls = 'day';
      if (d.done === 'yes') cls += ' d-yes';
      else if (d.done === 'partial') cls += ' d-half';
      else if (d.done === 'no') cls += ' d-no';
      else if (d.excused) cls += ' d-ex';
      else if (d.future) cls += ' d-future';
      if (d.date === today) cls += ' d-today';
      return el('div', { class: cls, title: d.date }, [
        el('span', { text: names[dt.getDay()] || '' }),
        el('b', { text: String(dt.getDate()) }),
        el('i', {})
      ]);
    }));
  }

  function screenHome() {
    var c = state.checkin || {};
    var plan = state.plan || {};
    var meta = plan.meta || {};
    var pl = c.plan || state.today || {};
    var day = pl.day || plan.current_day || 0;
    var pct = Math.max(0, Math.min(100, Math.round(day / 180 * 100)));
    var quiet = c.state === 'recovery' || c.state === 'dormant' || !!(state.progress && state.progress.quiet);

    var blocks = [greetHeader()];

    // Режим тишины (протокол безопасности): очки скрыты, помощь — в одно нажатие.
    if (state.progress && state.progress.quiet) {
      blocks.push(el('div', { class: 'card card-quiet' }, [
        el('p', { style: 'margin:0 0 10px', text: t('safety.quiet') }),
        el('button', { class: 'btn btn-ghost', text: t('safety.title'), onclick: function () {
          api('GET', '/api/safety/state').then(function (r) { if (r.ok) render(screenSafety(r)); });
        } })
      ]));
    }

    // Состояние, если человек выпадал
    if (c.state && c.state !== 'active' && c.state_hint) {
      blocks.push(el('div', { class: 'card card-quiet' }, [
        el('div', { class: 'eyebrow', text: c.state_title }),
        el('p', { style: 'margin:0', text: c.state_hint })
      ]));
    }

    // Сезон: день крупно и кольцо пройденного
    blocks.push(el('div', { class: 'card card-accent' }, [
      el('div', { class: 'hero' }, [
        el('div', { class: 'hero-l' }, [
          el('div', { class: 'eyebrow', text: t('ui.season_day') + (pl.chapter ? ' · ' + t('ui.chapter_n', { n: pl.chapter }) : '') }),
          el('div', { class: 'big' }, [String(Math.max(0, day)), el('span', { class: 'unit', text: t('plan.of_180') })]),
          pl.chapter_title ? el('div', { class: 'hero-sub', text: pl.chapter_title + (pl.deload ? ' · ' + t('plan.deload') : '') }) : null
        ]),
        ring(day > 0 ? Math.max(pct, 1) : 0, pct + '%', t('ui.of_season'))
      ])
    ]));

    if (c.week) {
      blocks.push(el('div', { class: 'card', style: 'padding:10px 8px' }, [daysStrip(c.week, c.date)]));
    }

    // Действие на сегодня и отметка
    if (pl.action) {
      var a = pl.action;
      var title = a.target ? String(a.title).split('{target}').join(String(a.target)) : a.title;
      blocks.push(el('div', { class: 'card' }, [
        el('div', { class: 'today' }, [
          bubble('target'),
          el('div', {}, [
            el('div', { class: 'eyebrow', text: t('plan.today') }),
            el('h2', { text: title }),
            a.hint ? el('p', { class: 'muted', text: a.hint }) : null
          ])
        ]),
        // Облегчение по флагам нагрузки или после паузы — с причиной и сроком.
        pl.adjustment ? el('div', { style: 'margin-bottom:12px' }, [note('info', t('plan.adjust.' + pl.adjustment.level, {
          pct: Math.round((1 - pl.adjustment.factor) * 100), date: fmtDate(pl.adjustment.until)
        }))]) : null,
        c.recorded
          ? el('div', { class: 'done-chip' }, [
              el('span', {}, [icon('check'), t('checkin.done_today')]),
              el('button', { class: 'btn btn-ghost', text: t('ui.change'), onclick: function () { go(screenCheckin); } })
            ])
          : actionButton(t('checkin.save'), 'btn-primary', function () { go(screenCheckin); })
      ]));
    } else if (pl.finished) {
      blocks.push(el('div', { class: 'card' }, [el('h2', { text: t('plan.finished') })]));
    }

    // Бенто: неделя, серия, щиты, уровень, план
    if (c.week) {
      var w = c.week;
      var left = Math.max(0, w.norm_days - w.done_days);
      var tiles = [
        tile('tile-lime', t('ui.tile.week'), 'calendar', w.done_days, '/ ' + w.norm_days,
          w.kept ? t('checkin.week_kept') : t('checkin.week_left', { n: left }),
          el('div', { class: 'bar' }, [el('i', { style: 'width:' + Math.min(100, Math.round(w.done_days / Math.max(1, w.norm_days) * 100)) + '%' })])),
        tile('tile-peach', t('ui.tile.streak'), 'flame', c.streak || 0, '', t('checkin.streak')),
        tile('tile-sky', t('ui.tile.shields'), 'shield', c.shields != null ? c.shields : 0, '', t('checkin.shields'))
      ];
      // Очки — скрыты в режиме восстановления: соревнование сейчас не помогает
      if (state.progress && !quiet) {
        tiles.push(tile('tile-lilac', t('gami.level'), 'bolt', state.progress.level, '', state.progress.xp + ' ' + t('gami.xp') + ' · ' + t('gami.to_next', { n: state.progress.xp_to_next })));
      } else {
        tiles.push(tile('', t('ui.tile.event'), 'calendar', '+', '', t('checkin.mark_event'), null, function () { go(screenEvent); }));
      }
      if (meta.weight_start) {
        tiles.push(el('button', { type: 'button', class: 'tile tile-wide', onclick: function () { go(screenChapters); } }, [
          el('div', { class: 'tile-top' }, [el('span', { text: t('plan.title') + (plan.tier ? ' · ' + t('tier.' + plan.tier) : '') }), bubble('scale')]),
          el('div', { class: 'bento', style: 'gap:10px' }, [
            el('div', {}, [el('div', { class: 'tile-v', style: 'font-size:24px' }, [meta.weight_start + '→' + meta.weight_final, el('span', { class: 'unit', text: t('ui.kg') })]), el('div', { class: 'tile-s', text: t('ui.from_to') })]),
            el('div', {}, [el('div', { class: 'tile-v', style: 'font-size:24px' }, [meta.steps_start + '→' + meta.steps_final]), el('div', { class: 'tile-s', text: t('ui.steps') })])
          ]),
          el('div', { class: 'tile-s', style: 'font-weight:700;color:var(--ink)', text: t('ui.show_chapters') + ' →' })
        ]));
      }
      blocks.push(el('div', { class: 'bento' }, tiles));
      if (state.progress && !quiet) {
        blocks.push(el('button', { class: 'btn btn-quiet', text: '+ ' + t('checkin.mark_event'), onclick: function () { go(screenEvent); } }));
      }
      if (plan.limited) blocks.push(note('info', t('plan.limited_note')));
    }

    // Сквад — эмоциональный якорь продукта
    var sqCard = squadCard(quiet);
    if (sqCard) {
      blocks.push(el('div', { class: 'section-title' }, [el('h3', { text: t('squad.title') })]));
      blocks.push(sqCard);
    }

    var cCard = coachCard();
    if (cCard) blocks.push(cCard);
    var wCard = wearCard();
    if (wCard) blocks.push(wCard);
    var bCard = billingCard();
    if (bCard) blocks.push(bCard);

    if (state.user && state.user.needs_phone) blocks.push(note('info', t('ui.attach_phone')));
    blocks.push(navbar('home'));

    return el('div', { class: 'stack' }, blocks);
  }

  /** Чек-ин: три нажатия и кнопка. Всё, что можно не спрашивать, не спрашиваем. */
  function screenCheckin() {
    var c = state.checkin || {};
    var entry = c.entry || {};
    var picked = {
      done: entry.done || null,
      energy: entry.energy || null,
      mood: entry.mood || null,
      skip_reason: entry.skip_reason || null
    };

    var msg = el('div', {});
    var submit = actionButton(t('checkin.save'), 'btn-primary');
    var reasonBox = el('div', { class: 'stack' }, []);
    reasonBox.hidden = picked.done !== 'no';

    function refreshSubmit() {
      submit.disabled = !picked.done
        || !picked.energy || !picked.mood
        || (picked.done === 'no' && !picked.skip_reason);
    }

    // Как прошёл день
    var doneBox = el('div', { class: 'opts opts-3' }, []);
    ['yes', 'partial', 'no'].forEach(function (v) {
      var b = choice(t('checkin.done.' + v), picked.done === v, function () {
        picked.done = v;
        Array.prototype.forEach.call(doneBox.children, function (x) { x.classList.remove('opt-on'); });
        b.classList.add('opt-on');
        reasonBox.hidden = v !== 'no';
        refreshSubmit();
      });
      doneBox.appendChild(b);
    });

    // Причина — только если не вышло
    var reasons = el('div', { class: 'opts' }, []);
    ['no_time', 'tired', 'sick', 'event', 'forgot', 'didnt_want'].forEach(function (v) {
      var b = choice(t('checkin.reason.' + v), picked.skip_reason === v, function () {
        picked.skip_reason = v;
        Array.prototype.forEach.call(reasons.children, function (x) { x.classList.remove('opt-on'); });
        b.classList.add('opt-on');
        refreshSubmit();
      });
      reasons.appendChild(b);
    });
    reasonBox.appendChild(el('div', { class: 'eyebrow', text: t('checkin.why') }));
    reasonBox.appendChild(reasons);

    function scale(label, key) {
      var box = el('div', { class: 'opts opts-5' }, []);
      [1, 2, 3, 4, 5].forEach(function (n) {
        var b = choice(String(n), picked[key] === n, function () {
          picked[key] = n;
          Array.prototype.forEach.call(box.children, function (x) { x.classList.remove('opt-on'); });
          b.classList.add('opt-on');
          refreshSubmit();
        });
        box.appendChild(b);
      });
      return el('div', { class: 'stack' }, [el('div', { class: 'eyebrow', text: label }), box]);
    }

    refreshSubmit();

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/checkin', picked).then(function (r) {
          busy(submit, false);
          if (r.ok) {
            // Ответ тренера, приветствие после возврата или протокол
            // безопасности — показываем, прежде чем вернуться домой.
            render(screenAfterCheckin(r));
            return;
          }
          msg.textContent = '';
          msg.appendChild(note('error', r.message || t('ui.network_error')));
        });
      }
    }, [
      el('div', { class: 'eyebrow', text: t('checkin.title') }),
      doneBox,
      reasonBox,
      scale(t('checkin.energy'), 'energy'),
      scale(t('checkin.mood'), 'mood'),
      msg,
      submit
    ]);

    var act = c.plan && c.plan.action ? c.plan.action : null;
    var actionTitle = act ? (act.target ? String(act.title).split('{target}').join(String(act.target)) : act.title) : '';
    return el('div', { class: 'stack' }, [
      header(),
      actionTitle ? el('h2', { text: actionTitle }) : null,
      form,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { go(screenHome); } })
    ].filter(Boolean));
  }

  /** Отметка события: тўй, болезнь, поездка, пост, отпуск. */
  function screenEvent() {
    var picked = { type: null };
    var msg = el('div', {});
    var submit = actionButton(t('ui.save'), 'btn-primary');
    submit.disabled = true;

    var box = el('div', { class: 'opts' }, []);
    ['toy', 'illness', 'trip', 'fasting', 'vacation'].forEach(function (v) {
      var b = choice(t('checkin.event.' + v), false, function () {
        picked.type = v;
        Array.prototype.forEach.call(box.children, function (x) { x.classList.remove('opt-on'); });
        b.classList.add('opt-on');
        submit.disabled = false;
      });
      box.appendChild(b);
    });

    var today = new Date().toISOString().slice(0, 10);
    var from = field(t('checkin.event_from'), { type: 'date', value: today });
    var to = field(t('checkin.event_to'), { type: 'date', value: today });

    var form = el('form', {
      class: 'stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(submit, true);
        api('POST', '/api/checkin/event', {
          type: picked.type, date_from: from.input.value, date_to: to.input.value
        }).then(function (r) {
          busy(submit, false);
          if (r.ok) return loadHome();
          msg.textContent = '';
          msg.appendChild(note('error', r.message || t('ui.network_error')));
        });
      }
    }, [box, from.wrap, to.wrap, msg, submit]);

    return el('div', { class: 'stack' }, [
      header(),
      el('h2', { text: t('checkin.mark_event') }),
      el('p', { class: 'muted', text: t('checkin.event_intro') }),
      form,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { go(screenHome); } })
    ]);
  }

  function screenChapters() {
    var plan = state.plan || {};
    var day = plan.current_day || 0;
    var list = el('div', { class: 'stack' }, []);

    (plan.chapters || []).forEach(function (c) {
      var active = day >= c.from_day && day <= c.to_day;
      var passed = day > c.to_day;
      list.appendChild(el('div', { class: 'card chapter' + (active ? ' chapter-on' : '') }, [
        el('div', { class: 'chapter-head' }, [
          el('div', { class: 'chapter-n', text: String(c.n) }),
          el('div', {}, [
            el('h3', { text: c.title }),
            el('div', { class: 'muted', text: t('ui.days_range', { a: c.from_day, b: c.to_day }) })
          ]),
          passed ? el('div', { class: 'chip-done', text: '✓' }) : null
        ]),
        el('p', { class: 'muted', text: c.subtitle }),
        el('div', { class: 'rows' }, [
          c.weight_target ? row(t('ui.weight_by_end'), c.weight_target + ' ' + t('ui.kg')) : null,
          row(t('ui.steps'), String(c.steps_target)),
          c.minutes ? row(t('ui.minutes'), String(c.minutes)) : null
        ].filter(Boolean))
      ]));
    });

    return el('div', { class: 'stack' }, [
      header(), el('h2', { text: t('plan.title') }),
      el('p', { class: 'muted', text: t('plan.safety_note') }),
      list,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { go(screenHome); } })
    ]);
  }

  function row(label, value) {
    return el('div', { class: 'row' }, [el('span', { text: label }), el('span', { text: String(value) })]);
  }

  // ---------- согласие на данные о здоровье (Р-19) ----------

  /** Отдельный экран, а не галочка в оферте. Без согласия анкета не уходит. */
  function screenConsent() {
    var agreed = !!state.answers.consent_health;
    var next = actionButton(t('consent.next'), 'btn-primary', function (e) {
      e.preventDefault();
      if (!agreed) return;
      state.answers.consent_health = 1;
      state.qIndex = 0;
      go(screenQuestion);
    });
    next.disabled = !agreed;
    var box = choice(t('consent.agree'), agreed, function () {
      agreed = !agreed;
      box.classList.toggle('opt-on', agreed);
      next.disabled = !agreed;
    });
    return el('div', { class: 'stack' }, [
      header(t('consent.title')),
      el('div', { class: 'card' }, [el('p', { style: 'margin:0', text: t('consent.text') })]),
      el('div', { class: 'opts' }, [box]),
      el('div', { class: 'spacer' }),
      next,
      isModerator() ? el('button', { class: 'btn btn-ghost', text: t('admin.title'), onclick: openAdmin }) : null
    ].filter(Boolean));
  }

  // ---------- тренер ----------

  /** Ответ на чек-ин. Если сработал протокол безопасности — только его текст. */
  function screenAfterCheckin(r) {
    if (r.safety) return screenSafety(r.safety);
    var blocks = [header()];
    if (r.returned) {
      blocks.push(el('div', { class: 'card card-accent center' }, [
        el('div', { class: 'tier', text: '+100' }),
        el('h2', { text: t('checkin.welcome_back') }),
        el('p', { class: 'muted', text: t('gami.comeback_note') })
      ]));
    } else {
      blocks.push(note('ok', r.message || t('checkin.saved')));
    }
    if (r.coach && r.coach.text) {
      blocks.push(el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('coach.title') }),
        el('p', { style: 'margin:0', text: r.coach.text })
      ]));
    }
    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(actionButton(t('ui.next'), 'btn-primary', function () { loadHome(); }));
    return el('div', { class: 'stack' }, blocks);
  }

  function screenSafety(help) {
    return el('div', { class: 'stack' }, [
      header(t('safety.title')),
      el('div', { class: 'card card-accent' }, [el('p', { style: 'margin:0', text: help.text })]),
      el('div', { class: 'card' }, [el('p', { class: 'pre', style: 'margin:0', text: help.contacts })]),
      el('div', { class: 'spacer' }),
      actionButton(t('ui.next'), 'btn-ghost', function () { loadHome(); })
    ]);
  }

  function coachCard() {
    var c = state.coach;
    if (!c) return null;
    var blocks = [el('div', { class: 'card-head' }, [el('div', { class: 'eyebrow', text: t('coach.title') }), bubble('sparkle')])];
    if (!c.consent_asked) {
      blocks.push(el('h2', { text: t('coach.consent.title') }));
      blocks.push(el('p', { class: 'muted', text: t('coach.consent.text') }));
      blocks.push(el('div', { class: 'opts opts-2' }, [
        choice(t('coach.consent.yes'), false, function () { api('POST', '/api/coach/consent', { ai: true }).then(loadHome); }),
        choice(t('coach.consent.no'), false, function () { api('POST', '/api/coach/consent', { ai: false }).then(loadHome); })
      ]));
    }
    blocks.push(el('button', { class: 'btn btn-ghost', text: t('coach.week_open'), onclick: openWeek }));
    return el('div', { class: 'card' }, blocks);
  }

  function openWeek() {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));
    api('GET', '/api/coach/week').then(function (r) {
      state.week = r.ok ? r.review : null;
      go(screenWeek);
    });
  }

  function screenWeek() {
    var w = state.week;
    var msg = el('div', {});
    function rate(useful) {
      api('POST', '/api/coach/feedback', { id: w.id, useful: useful }).then(function () {
        msg.textContent = '';
        msg.appendChild(note('ok', t('coach.thanks')));
      });
    }
    var c = state.coach || {};
    return el('div', { class: 'stack' }, [
      header(t('coach.week')),
      w ? el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('coach.source.' + w.source) }),
        el('p', { style: 'margin:0', text: w.text })
      ]) : note('error', t('ui.network_error')),
      w && w.useful === null ? el('div', { class: 'opts opts-2' }, [
        choice(t('coach.useful'), false, function () { rate(true); }),
        choice(t('coach.not_useful'), false, function () { rate(false); })
      ]) : null,
      msg,
      el('p', { class: 'muted', text: t('coach.disclaimer') }),
      c.consent_ai ? el('button', { class: 'btn btn-quiet', text: t('coach.consent.revoke'), onclick: function () {
        api('POST', '/api/coach/consent', { ai: false }).then(function () { msg.textContent = ''; msg.appendChild(note('info', t('coach.consent.off'))); });
      } }) : null,
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ].filter(Boolean));
  }

  // ---------- браслет ----------

  function wearCard() {
    var w = state.wear;
    if (!w) return null;
    var last = (w.days || [])[0];
    var stats = last ? [
      last.steps != null ? tile('', t('wear.field.steps'), 'steps', last.steps, '', fmtDate(last.date)) : null,
      last.sleep_min != null ? tile('tile-lilac', t('wear.field.sleep_h'), 'moon', (Math.round(last.sleep_min / 6) / 10).toString(), '', null) : null,
      last.rhr != null ? tile('tile-peach', t('wear.field.rhr'), 'heart', last.rhr, '', null) : null
    ].filter(Boolean) : [];
    return el('div', { class: 'card' }, [
      el('div', { class: 'card-head' }, [el('div', { class: 'eyebrow', text: t('wear.title') }), bubble('watch')]),
      stats.length ? el('div', { class: 'bento' }, stats) : el('p', { class: 'muted', style: 'margin:0', text: t('wear.hint') }),
      el('button', { class: 'btn btn-ghost', style: 'margin-top:14px', text: t('wear.open'), onclick: function () { go(screenWearable); } })
    ]);
  }

  function screenWearable() {
    var w = state.wear || {};
    var msg = el('div', {});
    var day = { date: new Date().toISOString().slice(0, 10) };
    var y = new Date(Date.now() - 86400000).toISOString().slice(0, 10);

    var dayBox = el('div', { class: 'opts opts-2' }, []);
    [[day.date, t('wear.today')], [y, t('wear.yesterday')]].forEach(function (p) {
      var b = choice(p[1], day.date === p[0], function () {
        day.date = p[0];
        Array.prototype.forEach.call(dayBox.children, function (x) { x.classList.remove('opt-on'); });
        b.classList.add('opt-on');
      });
      dayBox.appendChild(b);
    });

    var steps = field(t('wear.field.steps'), { type: 'number', inputmode: 'numeric', min: 0, max: 60000 });
    var sleep = field(t('wear.field.sleep_h'), { type: 'number', inputmode: 'decimal', step: '0.1', min: 0, max: 16 });
    var rhr = field(t('wear.field.rhr'), { type: 'number', inputmode: 'numeric', min: 30, max: 130 });
    var submit = actionButton(t('wear.save'), 'btn-primary');

    function show(kind, text) { msg.textContent = ''; msg.appendChild(note(kind, text)); }
    function reload() {
      api('GET', '/api/wearable/days').then(function (r) { if (r.ok) { state.wear = r; go(screenWearable); } });
    }

    var form = el('form', {
      class: 'card stack',
      onsubmit: function (e) {
        e.preventDefault();
        var body = { date: day.date };
        if (steps.input.value !== '') body.steps = steps.input.value;
        if (sleep.input.value !== '') body.sleep_min = Math.round(parseFloat(String(sleep.input.value).replace(',', '.')) * 60);
        if (rhr.input.value !== '') body.rhr = rhr.input.value;
        busy(submit, true);
        api('POST', '/api/wearable/day', body).then(function (r) {
          busy(submit, false);
          if (!r.ok) return show('error', r.message || t('ui.network_error'));
          if (r.data && r.data.suspect) return show('info', t('wear.suspect'));
          reload();
        });
      }
    }, [el('div', { class: 'eyebrow', text: t('wear.for_date') }), dayBox, steps.wrap, sleep.wrap, rhr.wrap, submit]);

    var file = el('input', { type: 'file', accept: '.csv,text/csv,text/plain' });
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) return;
      var reader = new FileReader();
      reader.onload = function () {
        api('POST', '/api/wearable/import', { csv: String(reader.result || '') }).then(function (r) {
          if (!r.ok) return show('error', r.message || t('ui.network_error'));
          show('ok', t('wear.import_done', { n: r.data.days }));
          setTimeout(reload, 900);
        });
      };
      reader.readAsText(f);
    });

    var weight = field(t('wear.weight_kg'), { type: 'number', inputmode: 'decimal', step: '0.1', min: 35, max: 300 });
    var waist = field(t('wear.waist_cm'), { type: 'number', inputmode: 'decimal', step: '0.5', min: 40, max: 250 });
    var wSubmit = actionButton(t('wear.save'), 'btn-ghost');
    var weightForm = el('form', {
      class: 'card stack',
      onsubmit: function (e) {
        e.preventDefault();
        busy(wSubmit, true);
        api('POST', '/api/wearable/weight', { weight_kg: weight.input.value, waist_cm: waist.input.value || null }).then(function (r) {
          busy(wSubmit, false);
          if (!r.ok) return show('error', r.message || t('ui.network_error'));
          reload();
        });
      }
    }, [
      el('div', { class: 'eyebrow', text: t('wear.weight') }),
      el('p', { class: 'muted', style: 'margin:0', text: t('wear.weight_hint') }),
      weight.wrap, waist.wrap, wSubmit,
      (w.weights || []).length ? el('div', { class: 'rows' }, w.weights.map(function (x) { return row(fmtDate(x.week_end), x.weight_kg + ' ' + t('ui.kg')); })) : null
    ].filter(Boolean));

    return el('div', { class: 'stack' }, [
      header(t('wear.title')),
      el('p', { class: 'muted', style: 'margin:0', text: t('wear.hint') }),
      msg,
      form,
      (w.days || []).length ? el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('wear.recent') }),
        el('div', { class: 'rows' }, w.days.slice(0, 7).map(function (d) {
          var parts = [];
          if (d.steps != null) parts.push(d.steps + (d.suspect ? ' ?' : ''));
          if (d.sleep_min != null) parts.push((Math.round(d.sleep_min / 6) / 10) + ' ' + t('wear.h'));
          if (d.rhr != null) parts.push('♥ ' + d.rhr);
          return row(fmtDate(d.date), parts.join(' · '));
        }))
      ]) : null,
      el('div', { class: 'card stack' }, [
        el('div', { class: 'eyebrow', text: t('wear.import') }),
        el('p', { class: 'muted', style: 'margin:0', text: t('wear.import_hint') }),
        file
      ]),
      weightForm,
      el('p', { class: 'muted', text: t('wear.calories_note') }),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ].filter(Boolean));
  }

  // ---------- сквад ----------

  function fmtDate(iso) {
    if (!iso) return '';
    var p = String(iso).slice(0, 10).split('-');
    return p.length === 3 ? p[2] + '.' + p[1] : iso;
  }

  function openChat(link) {
    if (!link) return;
    if (TG && TG.openTelegramLink && link.indexOf('https://t.me/') === 0) TG.openTelegramLink(link);
    else window.open(link, '_blank', 'noopener');
  }

  function copyText(text, msgBox) {
    function done() {
      msgBox.textContent = '';
      msgBox.appendChild(note('ok', t('squad.leader.copied')));
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done, function () { window.prompt('', text); });
    } else {
      window.prompt('', text);
    }
  }

  /** Статусы — без осуждения: «на паузе», а не «пропустил». Очков других людей не показываем. */
  function memberLine(m, i) {
    var tags = [];
    if (m.me) tags.push(t('squad.me'));
    if (m.leader) tags.push(t('squad.leader'));
    if (m.anchor) tags.push(t('squad.anchor'));
    return el('div', { class: 'row member' }, [
      el('div', { class: 'member-l' }, [
        face(m.name, i),
        el('span', {}, [m.name, tags.length ? el('small', { text: ' · ' + tags.join(' · ') }) : null])
      ]),
      el('span', { class: 'mstatus ms-' + m.status, text: t('squad.status.' + m.status) })
    ]);
  }

  /** Быстрые действия сквада: чат, лента, созвоны — что из этого включено. */
  function squadQuick(sq) {
    var btns = [];
    if (sq && sq.chat_link) btns.push(el('button', { type: 'button', class: 'q-tg', onclick: function () { openChat(sq.chat_link); } }, [icon('chat'), t('ui.chat')]));
    if (hasModule('feed.open')) btns.push(el('button', { type: 'button', onclick: function () { openFeed(); } }, [icon('image'), t('feed.open')]));
    if (hasModule('calls.open')) btns.push(el('button', { type: 'button', onclick: function () { openCalls(); } }, [icon('video'), t('calls.open')]));
    return btns.length ? el('div', { class: 'quick' + (btns.length === 2 ? ' quick-2' : '') }, btns) : null;
  }

  function squadCard(quiet) {
    var s = state.squad;
    if (!s || s.status === 'none') return null;

    if (s.status === 'waiting') {
      return el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('squad.title') }),
        el('h2', { text: t('squad.waiting') }),
        s.wave
          ? el('div', { class: 'rows' }, [row(t('squad.wave_starts', { date: fmtDate(s.wave.start_date) }), t('squad.days_left', { n: s.wave.days_left }))])
          : el('p', { class: 'muted', text: t('squad.no_wave') }),
        el('p', { class: 'muted', text: t('squad.waiting_hint') }),
        s.needs_season ? note('info', t('billing.needs_season')) : null,
        s.needs_season ? el('button', { class: 'btn btn-primary', text: t('billing.buy'), onclick: openBilling }) : null,
        !s.prefs_set
          ? el('button', { class: 'btn btn-ghost', text: t('squad.prefs.title'), onclick: function () { go(screenSquadPrefs); } })
          : null
      ]);
    }

    var sq = s.squad;
    return el('div', { class: 'card' }, [
      el('div', { class: 'card-head' }, [
        el('div', {}, [
          el('div', { class: 'eyebrow', text: sq.name + ' · ' + t('squad.day', { n: sq.day }) }),
          el('div', { class: 'faces' }, sq.members.map(function (m, i) { return face(m.name, i); }))
        ]),
        // Счёт — соревновательный элемент, в восстановлении его не показываем.
        s.week && !quiet ? el('div', { class: 'pill pill-ink', title: t('squad.week_score') }, [icon('bolt'), String(s.week.score)]) : null
      ]),
      s.i_am_leader ? el('div', { style: 'margin-bottom:12px' }, [note('info', t('squad.leader_card', { date: fmtDate(sq.leader_until) }))]) : null,
      squadQuick(sq) || el('p', { class: 'muted', style: 'margin:0', text: t('squad.no_chat') }),
      el('div', { class: 'stack', style: 'margin-top:12px' }, [
        s.i_am_leader ? el('button', { class: 'btn btn-primary', text: t('squad.leader_open'), onclick: openLeader }) : null,
        el('button', { class: 'btn btn-ghost', text: t('squad.members') + ' · ' + sq.members.length, onclick: function () { go(screenSquad); } })
      ])
    ]);
  }

  function screenSquad() {
    var s = state.squad || {};
    var sq = s.squad;
    if (!sq) return screenHome();
    var w = s.week;

    return el('div', { class: 'stack' }, [
      header(sq.name),
      squadQuick(sq),
      el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('squad.members') + ' · ' + t('squad.day', { n: sq.day }) }),
        el('div', { class: 'rows' }, sq.members.map(memberLine))
      ]),
      w ?
      el('div', { class: 'card card-accent' }, [
        el('div', { class: 'eyebrow', text: t('squad.week') + ' · ' + t('squad.week_so_far') }),
        el('div', { class: 'big', style: 'margin:4px 0 10px', text: String(w.score) }),
        el('div', { class: 'rows' }, [
          row(t('squad.week_median'), w.median + '%'),
          row(t('squad.week_min'), w.min + '%')
        ]),
        el('p', { class: 'muted', style: 'margin:10px 0 0', text: t('squad.week_hint') })
      ]) : null,
      (s.history || []).length ? el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('squad.history') }),
        el('div', { class: 'rows' }, s.history.slice().reverse().map(function (h) {
          return row('#' + h.week_no + ' · ' + fmtDate(h.week_start), String(h.score));
        }))
      ]) : null,
      sq.chat_link ? null : note('info', t('squad.no_chat')),
      s.i_am_leader ? el('button', { class: 'btn btn-primary', text: t('squad.leader_open'), onclick: openLeader }) : null,
      navbar('squad')
    ].filter(Boolean));
  }

  /** Два вопроса для подбора. Смешанный сквад — только с явного согласия всех. */
  function screenSquadPrefs() {
    var s = state.squad || {};
    var picked = { mixed_ok: !!s.mixed_ok, commit: s.commit || 2 };
    var msg = el('div', {});

    function group(values, current, label, key) {
      var box = el('div', { class: 'opts opts-' + values.length }, []);
      values.forEach(function (v) {
        var b = choice(label(v), current === v, function () {
          picked[key] = v;
          Array.prototype.forEach.call(box.children, function (x) { x.classList.remove('opt-on'); });
          b.classList.add('opt-on');
        });
        box.appendChild(b);
      });
      return box;
    }

    var submit = actionButton(t('squad.prefs.save'), 'btn-primary');
    return el('div', { class: 'stack' }, [
      header(t('squad.prefs.title')),
      el('form', {
        class: 'stack',
        onsubmit: function (e) {
          e.preventDefault();
          busy(submit, true);
          api('POST', '/api/squad/prefs', picked).then(function (r) {
            busy(submit, false);
            if (r.ok) return loadHome();
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
        }
      }, [
        el('div', { class: 'eyebrow', text: t('squad.prefs.mixed') }),
        group([false, true], picked.mixed_ok, function (v) { return t('squad.prefs.mixed.' + (v ? 'yes' : 'no')); }, 'mixed_ok'),
        el('p', { class: 'muted', style: 'margin:0', text: t('squad.prefs.mixed_hint') }),
        el('div', { class: 'eyebrow', text: t('squad.prefs.commit') }),
        group([1, 2, 3], picked.commit, function (v) { return t('squad.prefs.commit.' + v); }, 'commit'),
        msg,
        submit
      ]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { go(screenHome); } })
    ]);
  }

  // ---------- панель лидера ----------

  function openLeader() {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));
    api('GET', '/api/squad/leader').then(function (r) {
      if (!r.ok) { return loadHome(); }
      state.leader = r.data;
      go(screenLeader);
    });
  }

  /** Три дела в неделю, черновики готовы. Лидер нажимает «скопировать» — человеческое остаётся за ним. */
  function screenLeader() {
    var L = state.leader || {};
    var msg = el('div', {});

    function draftBlock(title, draft, extra) {
      return el('div', { class: 'card' + (extra && extra.accent ? ' card-accent' : '') }, [
        el('div', { class: 'eyebrow', text: title }),
        el('p', { style: 'margin:0 0 12px', text: draft }),
        el('div', { class: 'stack' }, [
          el('button', { class: 'btn btn-ghost', text: t('squad.leader.copy'), onclick: function () { copyText(draft, msg); } }),
          extra && extra.button ? extra.button : null
        ])
      ]);
    }

    var blocks = [header(t('squad.leader.title')), el('p', { class: 'muted', text: t('squad.leader.intro') }), msg];

    var today = (L.duties || []).filter(function (d) { return d.today; })[0];
    if (today) {
      blocks.push(draftBlock(t('squad.leader.today') + ' · ' + today.title, today.draft, {
        accent: true,
        button: el('button', {
          class: 'btn btn-primary', text: t('squad.leader.done'),
          onclick: function () { api('POST', '/api/squad/leader/done').then(function () { loadHome(); }); }
        })
      }));
    }

    blocks.push(el('div', { class: 'eyebrow', text: t('squad.leader.paused') }));
    if (!(L.paused || []).length) {
      blocks.push(note('ok', t('squad.leader.nobody')));
    }
    (L.paused || []).forEach(function (p) {
      blocks.push(draftBlock(p.name + ' · ' + t('squad.status.' + p.status), p.draft, {
        button: el('button', {
          class: 'btn btn-quiet', text: t('squad.leader.help'),
          onclick: function () {
            api('POST', '/api/squad/help', { user_id: p.user_id }).then(function (r) {
              msg.textContent = '';
              msg.appendChild(note(r.ok ? 'ok' : 'error', r.ok ? t('squad.leader.help_sent') : (r.message || t('ui.network_error'))));
            });
          }
        })
      }));
    });

    blocks.push(el('div', { class: 'card' }, [
      el('div', { class: 'rows' }, (L.duties || []).map(function (d) {
        return el('div', { class: 'row duty' + (d.today ? ' row-on' : '') }, [el('span', { text: d.title })]);
      }))
    ]));
    blocks.push(note('info', t('squad.leader.never')));
    if (L.chat_link) {
      blocks.push(el('button', { class: 'btn btn-tg', text: t('squad.open_chat'), onclick: function () { openChat(L.chat_link); } }));
    }
    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(el('button', {
      class: 'btn btn-quiet', text: t('squad.leader.decline'),
      onclick: function () {
        api('POST', '/api/squad/leader/decline').then(function (r) {
          if (r.ok) {
            render(el('div', { class: 'stack' }, [
              header(), note('ok', t('squad.leader.declined')),
              actionButton(t('ui.next'), 'btn-primary', function () { loadHome(); })
            ]));
          }
        });
      }
    }));
    blocks.push(el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } }));
    return el('div', { class: 'stack' }, blocks);
  }

  // ---------- модератор: волны и составы ----------

  function isModerator() {
    return !!state.user && (state.user.role === 'admin' || state.user.role === 'moderator');
  }

  function adminLoad(path, key, screen) {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));
    return api('GET', path).then(function (r) {
      if (!r.ok) {
        render(el('div', { class: 'stack' }, [header(), note('error', r.message || r.error || t('ui.network_error')),
          el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })]));
        return;
      }
      state[key] = r;
      go(screen);
    });
  }

  function openAdmin() {
    // Сигналы безопасности — первыми: на них реакция в течение 2 часов.
    api('GET', '/api/admin/safety/alerts').then(function (r) {
      state.alerts = r.ok ? r.alerts : [];
      adminLoad('/api/admin/squad/waves', 'adminWaves', screenAdminWaves);
    });
  }

  function alertsCard() {
    var list = state.alerts || [];
    if (!list.length) return null;
    return el('div', { class: 'card card-accent' }, [
      el('div', { class: 'eyebrow', text: t('safety.alerts') + ' · ' + list.length }),
      el('div', { class: 'rows' }, list.map(function (a) {
        return el('div', { class: 'row' }, [
          el('span', {}, [a.name, el('small', { class: 'muted', text: ' · ' + t('safety.source.' + a.source) + ' · ' + String(a.created_at).slice(0, 16).replace('T', ' ') })]),
          el('button', { class: 'link', text: t('safety.resolve'), onclick: function () {
            api('POST', '/api/admin/safety/alerts/' + a.id + '/resolve').then(openAdmin);
          } })
        ]);
      }))
    ]);
  }
  function openAdminWave(id) { state.adminWaveId = id; adminLoad('/api/admin/squad/waves/' + id, 'adminWave', screenAdminWave); }
  function openAdminActive() { adminLoad('/api/admin/squad/active', 'adminActive', screenAdminActive); }

  function adminPost(path, body, then) {
    return api('POST', path, body || {}).then(function (r) {
      if (!r.ok) {
        var text = r.message || r.error || t('ui.network_error');
        if (r.rules && r.rules.length) text += ' — ' + r.rules.map(function (x) { return x.title; }).join('; ');
        window.alert(text);
      }
      if (then) then(r);
      return r;
    });
  }

  function screenAdminWaves() {
    var d = state.adminWaves || {};
    var date = field(t('squad.admin.start_date'), { type: 'date', required: true });
    var title = field(t('squad.admin.wave_title'), { type: 'text', maxlength: 80 });
    var submit = actionButton(t('squad.admin.create'), 'btn-primary');
    var msg = el('div', {});

    return el('div', { class: 'stack' }, [
      header(t('admin.title')),
      adminTabs('squads'),
      alertsCard(),
      d.unassigned ? note('info', t('squad.admin.unassigned', { n: d.unassigned })) : null,
      el('form', {
        class: 'card stack',
        onsubmit: function (e) {
          e.preventDefault();
          busy(submit, true);
          api('POST', '/api/admin/squad/waves', { start_date: date.input.value, title: title.input.value }).then(function (r) {
            busy(submit, false);
            if (r.ok) return openAdmin();
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
        }
      }, [el('div', { class: 'eyebrow', text: t('squad.admin.new_wave') }), date.wrap, title.wrap, msg, submit]),
      el('div', { class: 'eyebrow', text: t('squad.admin.waves') }),
      el('div', { class: 'card' }, [el('div', { class: 'rows' }, (d.waves || []).map(function (w) {
        return el('div', { class: 'row' }, [
          el('span', {}, [
            (w.title || '#' + w.id) + ' · ' + fmtDate(w.start_date),
            el('small', { class: 'muted', text: ' · ' + t('squad.admin.wave.' + w.status) + ' · ' + w.pool + ' ' + t('squad.admin.pool') + ' · ' + w.squads + ' ' + t('squad.admin.squads') })
          ]),
          el('button', { class: 'link', text: t('squad.admin.open'), onclick: function () { openAdminWave(w.id); } })
        ]);
      }))]),
      el('button', { class: 'btn btn-ghost', text: t('squad.admin.active'), onclick: openAdminActive }),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ].filter(Boolean));
  }

  function adminSquadCard(sq, targets, reload) {
    var head = '#' + sq.id + ' · ' + sq.lang.toUpperCase() + ' · ' + t('squad.sex.' + sq.sex) + ' · ' + sq.base_tier + ' · ' + t('squad.admin.status.' + sq.status);
    var actions = [];
    if (sq.status === 'proposed') {
      actions.push(el('button', { class: 'btn btn-primary', text: t('squad.admin.approve'), onclick: function () { adminPost('/api/admin/squad/' + sq.id + '/approve', {}, reload); } }));
    }
    if (sq.status === 'proposed' || sq.status === 'approved') {
      actions.push(el('button', { class: 'btn btn-quiet', text: t('squad.admin.reject'), onclick: function () { adminPost('/api/admin/squad/' + sq.id + '/reject', {}, reload); } }));
    }
    if (sq.status === 'active' || sq.status === 'approved') {
      var link = field(t('squad.admin.link'), { type: 'url', value: sq.invite_link || '', placeholder: 'https://t.me/+…' });
      actions.push(link.wrap);
      actions.push(el('button', { class: 'btn btn-ghost', text: t('squad.admin.save_link'), onclick: function () { adminPost('/api/admin/squad/' + sq.id + '/link', { invite_link: link.input.value }, reload); } }));
    }
    if (sq.status === 'active') {
      actions.push(el('button', { class: 'btn btn-quiet', text: t('squad.admin.disband'), onclick: function () {
        if (window.confirm(t('squad.admin.disband') + '?')) adminPost('/api/admin/squad/' + sq.id + '/disband', {}, reload);
      } }));
    }

    return el('div', { class: 'card' }, [
      el('div', { class: 'eyebrow', text: head }),
      el('div', { class: 'chips' }, (sq.flags || []).map(function (f) { return el('span', { class: 'chip', text: t('squad.flag.' + f) }); })),
      (sq.rules || []).length ? note('error', sq.rules.map(function (r) { return t('squad.rule.' + r); }).join('; ')) : null,
      el('div', { class: 'rows' }, [
        row(t('squad.admin.cost'), String(sq.cost)),
        row(t('squad.admin.code', { code: sq.code }), sq.chat_bound ? '✓' : '—')
      ]),
      el('div', { class: 'rows' }, sq.members.map(function (m) {
        return el('div', { class: 'row member' }, [
          el('span', {}, [m.name + (m.role === 'anchor' ? ' ★' : '') + (m.needs_help ? ' ⚑' : ''),
            el('small', { class: 'muted', text: ' · ' + m.tier + ' · ' + m.age + ' · ' + t('squad.window.' + m.window) + ' · ' + m.time + "'" + ' · c' + m.commit })]),
          el('span', { class: 'mstatus ms-' + m.status, text: t('squad.status.' + m.status) })
        ]);
      })),
      el('div', { class: 'stack', style: 'margin-top:12px' }, actions)
    ].filter(Boolean));
  }

  function screenAdminWave() {
    var d = state.adminWave || {};
    var w = d.wave || {};
    var reload = function () { openAdminWave(w.id); };
    var open = (d.squads || []).filter(function (s) { return s.status === 'proposed' || s.status === 'approved'; });

    var blocks = [
      header((w.title || '#' + w.id) + ' · ' + fmtDate(w.start_date)),
      el('div', { class: 'rows' }, [row(t('squad.admin.wave.' + w.status), (d.waiting || []).length + ' ' + t('squad.admin.pool'))])
    ];
    if (w.status !== 'started') {
      blocks.push(el('button', { class: 'btn btn-primary', text: t('squad.admin.match'), onclick: function () { adminPost('/api/admin/squad/waves/' + w.id + '/match', {}, reload); } }));
      blocks.push(el('p', { class: 'muted', style: 'margin:0', text: t('squad.admin.rematch_note') }));
      blocks.push(el('button', { class: 'btn btn-ghost', text: t('squad.admin.start'), onclick: function () { adminPost('/api/admin/squad/waves/' + w.id + '/start', {}, reload); } }));
    }
    (d.squads || []).forEach(function (sq) { blocks.push(adminSquadCard(sq, open, reload)); });

    if ((d.waiting || []).length) {
      blocks.push(el('div', { class: 'eyebrow', text: t('squad.admin.left') }));
      blocks.push(el('div', { class: 'card' }, [el('div', { class: 'rows' }, d.waiting.map(function (c) {
        var select = el('select', { class: 'mini' }, open.map(function (s) { return el('option', { value: s.id, text: '#' + s.id }); }));
        return el('div', { class: 'row' }, [
          el('span', {}, [c.name, el('small', { class: 'muted', text: ' · ' + c.lang + ' · ' + t('squad.sex.' + c.sex) + ' · ' + c.tier + ' · ' + c.age + ' · ' + t('squad.window.' + c.window) })]),
          open.length ? el('span', {}, [select, el('button', { class: 'link', text: t('squad.admin.move_here'), onclick: function () {
            adminPost('/api/admin/squad/move', { user_id: c.user_id, squad_id: parseInt(select.value, 10) }, reload);
          } })]) : null
        ]);
      }))]));
    }

    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: openAdmin }));
    return el('div', { class: 'stack' }, blocks);
  }

  function screenAdminActive() {
    var d = state.adminActive || {};
    return el('div', { class: 'stack' }, [header(t('squad.admin.active'))]
      .concat((d.squads || []).map(function (sq) { return adminSquadCard(sq, [], openAdminActive); }))
      .concat([el('div', { class: 'spacer' }), el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: openAdmin })]));
  }

  // ---------- сезон и оплата ----------

  function money(n) {
    return String(Math.round(n || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' ' + t('billing.sum');
  }

  function billingCard() {
    var b = state.billing;
    if (!b) return null;
    var st = b.status || {};
    var line = st.status === 'active' ? t('billing.status.active', { date: fmtDate(st.until) })
      : st.status === 'pending' ? t('billing.status.pending')
      : st.status === 'expired' ? t('billing.status.expired')
      : st.status === 'trial' ? (st.trial_left > 0 ? t('billing.status.trial', { n: st.trial_left }) : t('billing.status.trial_over'))
      : '';
    if (!line) return null;
    return el('div', { class: 'card' }, [
      el('div', { class: 'eyebrow', text: t('billing.title') }),
      el('p', { style: 'margin:0 0 10px', text: line }),
      st.next_due ? el('p', { class: 'muted', style: 'margin:0 0 10px', text: t('billing.next_due', { date: fmtDate(st.next_due) }) }) : null,
      st.status !== 'active' || st.next_due
        ? el('button', { class: 'btn ' + (st.status === 'pending' ? 'btn-ghost' : 'btn-primary'), text: st.status === 'pending' ? t('billing.pay.title') : t('billing.buy'), onclick: openBilling })
        : el('button', { class: 'btn btn-quiet', text: t('billing.ref.title'), onclick: openBilling })
    ].filter(Boolean));
  }

  function openBilling() {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));
    Promise.all([api('GET', '/api/billing'), api('GET', '/api/billing/duo')]).then(function (r) {
      state.billing = r[0].ok ? r[0] : state.billing;
      state.duo = r[1].ok ? r[1].codes : [];
      if (state.billing && state.billing.pending) { state.payment = { payment: state.billing.pending, instructions: state.billing.instructions }; }
      go(screenBilling);
    });
  }

  function screenBilling() {
    var b = state.billing || {};
    var msg = el('div', {});
    var promo = field(t('billing.promo'), { type: 'text', autocapitalize: 'characters', maxlength: 24 });
    function show(kind, text) { msg.textContent = ''; msg.appendChild(note(kind, text)); }

    function checkout(tariff) {
      api('POST', '/api/billing/checkout', { tariff: tariff, promo: promo.input.value }).then(function (r) {
        if (!r.ok) return show('error', r.message || t('ui.network_error'));
        if (r.data.activated) {
          render(el('div', { class: 'stack' }, [header(), note('ok', t('billing.activated')),
            actionButton(t('ui.next'), 'btn-primary', function () { loadHome(); })]));
          return;
        }
        state.payment = r.data;
        go(screenPay);
      });
    }

    var blocks = [header(t('billing.title')), el('p', { class: 'muted', style: 'margin:0', text: t('billing.free') }), msg];
    if (b.pending) {
      blocks.push(el('button', { class: 'btn btn-primary', text: t('billing.pay.title'), onclick: function () { state.payment = { payment: b.pending, instructions: b.instructions }; go(screenPay); } }));
    }
    if (b.credit > 0) blocks.push(note('info', t('billing.credit', { sum: String(b.credit).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') })));

    var active = b.status && b.status.status === 'active' && !b.status.next_due;
    if (!active && !b.pending) {
      (b.tariffs || []).forEach(function (tf) {
        blocks.push(el('div', { class: 'card' }, [
          el('div', { class: 'eyebrow', text: t('billing.tariff.' + tf.key) }),
          el('div', { class: 'daybar' }, [el('div', { class: 'n', style: 'font-size:28px', text: money(tf.price) })]),
          el('p', { class: 'muted', text: t('billing.about.' + tf.key, { times: tf.times || 6, price: String(tf.price).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') }) }),
          el('button', { class: 'btn btn-ghost', text: t('billing.choose'), onclick: function () { checkout(tf.key); } })
        ]));
      });
      blocks.push(el('div', { class: 'card stack' }, [promo.wrap]));
    } else if (b.status && b.status.next_due) {
      blocks.push(el('button', { class: 'btn btn-primary', text: t('billing.tariff.installment'), onclick: function () { checkout('installment'); } }));
    }

    (state.duo || []).forEach(function (d) {
      if (!Number(d.used)) blocks.push(note('info', t('billing.duo.code', { code: d.code })));
    });

    // Приглашение: свой код и ввод чужого.
    var refInput = field(t('billing.ref.enter'), { type: 'text', autocapitalize: 'characters', maxlength: 12 });
    blocks.push(el('div', { class: 'card stack' }, [
      el('div', { class: 'eyebrow', text: t('billing.ref.title') }),
      el('p', { style: 'margin:0', text: t('billing.ref.text', { code: b.ref_code || '—', bonus: '50 000' }) }),
      el('button', { class: 'btn btn-quiet', text: t('squad.leader.copy'), onclick: function () {
        copyText(location.origin + '/?ref=' + (b.ref_code || ''), msg);
      } }),
      b.status && b.status.status === 'trial' ? refInput.wrap : null,
      b.status && b.status.status === 'trial' ? el('button', { class: 'btn btn-ghost', text: t('ui.next'), onclick: function () {
        api('POST', '/api/billing/referral', { code: refInput.input.value }).then(function (r) {
          show(r.ok ? 'ok' : 'error', r.ok ? t('billing.ref.applied') : (r.message || t('ui.network_error')));
        });
      } }) : null
    ].filter(Boolean)));

    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } }));
    return el('div', { class: 'stack' }, blocks);
  }

  /** Реквизиты для перевода: сумма, код в комментарий, карта, «Я оплатил». */
  function screenPay() {
    var d = state.payment || {};
    var p = d.payment || {};
    var ins = d.instructions || { amount: p.amount, code: p.code, cards: (state.billing && state.billing.cards) || [], contact: '' };
    var msg = el('div', {});
    var noteField = field(t('billing.pay.note'), { type: 'text', inputmode: 'numeric', maxlength: 20 });
    return el('div', { class: 'stack' }, [
      header(t('billing.pay.title')),
      el('div', { class: 'card' }, [el('div', { class: 'rows' }, [
        row(t('billing.pay.amount'), money(ins.amount)),
        row(t('billing.pay.code'), ins.code || p.code || '')
      ])]),
      el('p', { class: 'muted', style: 'margin:0', text: t('billing.pay.code_hint') }),
      el('button', { class: 'btn btn-quiet', text: t('squad.leader.copy'), onclick: function () { copyText(ins.code || p.code || '', msg); } }),
      el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('billing.pay.cards') }),
        (ins.cards || []).length
          ? el('div', { class: 'rows' }, ins.cards.map(function (c) { return el('div', { class: 'row' }, [el('span', { class: 'pre', text: c })]); }))
          : el('p', { class: 'muted', style: 'margin:0', text: t('billing.pay.no_cards') })
      ]),
      ins.contact ? el('p', { class: 'muted', style: 'margin:0', text: t('billing.pay.contact', { contact: ins.contact }) }) : null,
      msg,
      p.marked_paid ? note('ok', t('billing.pay.marked')) : el('div', { class: 'stack' }, [
        noteField.wrap,
        actionButton(t('billing.pay.done'), 'btn-primary', function (e) {
          e.preventDefault();
          api('POST', '/api/billing/payments/' + p.id + '/paid', { note: noteField.input.value }).then(function (r) {
            if (r.ok) { p.marked_paid = true; go(screenPay); }
          });
        })
      ]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('billing.pay.cancel'), onclick: function () {
        api('POST', '/api/billing/payments/' + p.id + '/cancel').then(function () { loadHome(); });
      } }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ].filter(Boolean));
  }

  // ---------- мои данные (Р-19) ----------

  function download(path, filename) {
    var headers = {};
    if (state.token) headers['X-Session-Token'] = state.token;
    return fetch(path, { headers: headers, credentials: 'same-origin' }).then(function (res) { return res.blob(); }).then(function (blob) {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    });
  }

  /** Профиль: кто я, мои данные, сезон, панель модератора, выход и удаление. */
  function screenMe() {
    var msg = el('div', {});
    var word = field(t('me.erase_word'), { type: 'text', autocomplete: 'off' });
    var u = state.user || {};
    function item(ic, label, fn) {
      return el('button', { type: 'button', onclick: function () { fn(); } }, [
        bubble(ic), el('span', { text: label }), el('span', { class: 'ico chev', 'aria-hidden': 'true' }, [icon('chevron')])
      ]);
    }
    var menu = [
      item('download', t('me.export_json'), function () { download('/api/me/export?format=json', 'level180-export.json'); }),
      item('download', t('me.export_csv'), function () { download('/api/me/export?format=csv', 'level180-days.csv'); })
    ];
    if (state.billing) menu.push(item('card', t('billing.title'), openBilling));
    if (state.wear) menu.push(item('watch', t('wear.title'), function () { go(screenWearable); }));
    if (isModerator()) menu.push(item('settings', t('admin.title'), openAdmin));
    menu.push(item('logout', t('ui.logout'), logout));

    return el('div', { class: 'stack' }, [
      header(t('ui.nav.me')),
      el('div', { class: 'card profile' }, [
        el('span', { class: 'avatar avatar-xl', text: initial(u.name || 'L') }),
        el('h2', { text: u.name || 'LEVEL 180' }),
        u.phone ? el('div', { class: 'muted', text: u.phone }) : null,
        isModerator() ? el('span', { class: 'pill pill-lime', text: t('admin.title') }) : null
      ]),
      el('div', { class: 'card', style: 'padding:4px 18px' }, [el('div', { class: 'menu' }, menu)]),
      el('p', { class: 'muted', style: 'margin:0 4px', text: t('me.export_hint') }),
      state.coach && state.coach.consent_ai ? el('button', { class: 'btn btn-quiet', text: t('coach.consent.revoke'), onclick: function () {
        api('POST', '/api/coach/consent', { ai: false }).then(function () { msg.textContent = ''; msg.appendChild(note('info', t('coach.consent.off'))); });
      } }) : null,
      msg,
      el('div', { class: 'card stack' }, [
        el('div', { class: 'eyebrow', style: 'margin:0', text: t('me.erase') }),
        el('p', { class: 'muted', style: 'margin:0', text: t('me.erase_hint') }),
        word.wrap,
        el('button', { class: 'btn btn-ghost danger', text: t('me.erase'), onclick: function () {
          api('POST', '/api/me/erase', { confirm: word.input.value }).then(function (r) {
            if (!r.ok) { msg.textContent = ''; msg.appendChild(note('error', r.message || t('ui.network_error'))); return; }
            state.user = null; state.token = null; storage('token', null);
            render(el('div', { class: 'stack' }, [header(), note('ok', t('me.erased'))]));
          });
        } })
      ]),
      state.checkin ? navbar('me') : el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ].filter(Boolean));
  }

  // ---------- лента сквада (срез 7) ----------

  /** Модуль включён, если сервер прислал его строки: выключенный модуль — ни кнопки, ни пустого экрана. */
  function hasModule(key) { return !!state.strings[key]; }

  function loading() { render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))])); }

  function openFeed() {
    loading();
    api('GET', '/api/feed').then(function (r) {
      if (!r.ok) return loadHome();
      state.feed = r;
      go(screenFeed);
    });
  }

  function feedMore() {
    var f = state.feed || {};
    if (!f.before) return;
    api('GET', '/api/feed?before=' + f.before).then(function (r) {
      if (!r.ok) return;
      f.posts = (f.posts || []).concat(r.posts || []);
      f.before = r.before;
      go(screenFeed);
    });
  }

  function fmtTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  /** Полное фото поверх экрана. Нажатие — закрыть. */
  function viewPhoto(url) {
    if (!url) return;
    var layer = el('div', { class: 'photo-view', role: 'dialog', onclick: function () { layer.remove(); } }, [
      el('img', { src: url, alt: '' })
    ]);
    document.body.appendChild(layer);
  }

  function postCard(p) {
    var actions = el('div', { class: 'post-actions' });

    function drawActions() {
      actions.textContent = '';
      if (p.mine) {
        actions.appendChild(el('small', { class: 'muted', text: p.supporters && p.supporters.length ? t('feed.supporters', { names: p.supporters.join(', ') }) : t('feed.nobody_yet') }));
        actions.appendChild(el('button', { class: 'link danger', text: t('feed.delete'), onclick: function () {
          if (!window.confirm(t('feed.delete') + '?')) return;
          api('POST', '/api/feed/' + p.id + '/delete').then(function (r) { if (r.ok) openFeed(); });
        } }));
        return;
      }
      actions.appendChild(el('button', {
        class: 'opt support' + (p.supported ? ' opt-on' : ''),
        text: p.supported ? t('feed.supported') : t('feed.support'),
        onclick: function () {
          api('POST', '/api/feed/' + p.id + '/support').then(function (r) {
            if (r.ok) { p.supported = r.supported; drawActions(); }
          });
        }
      }));
      actions.appendChild(p.reported
        ? el('small', { class: 'muted', text: t('feed.reported') })
        : el('button', { class: 'link', text: t('feed.report'), onclick: drawReport }));
    }

    function drawReport() {
      actions.textContent = '';
      actions.appendChild(el('div', { class: 'eyebrow', text: t('feed.report.title') }));
      var box = el('div', { class: 'opts' });
      ((state.feed && state.feed.state && state.feed.state.reasons) || ['body', 'face', 'offensive', 'spam', 'other']).forEach(function (reason) {
        box.appendChild(choice(t('feed.reason.' + reason), false, function () {
          api('POST', '/api/feed/' + p.id + '/report', { reason: reason }).then(function (r) {
            if (r.ok && r.hidden) return openFeed();
            p.reported = true;
            drawActions();
          });
        }));
      });
      actions.appendChild(box);
      actions.appendChild(el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: drawActions }));
    }

    drawActions();
    return el('div', { class: 'card post' }, [
      el('div', { class: 'post-head' }, [
        face(p.author.name, p.author.id),
        el('div', {}, [el('b', { text: p.author.name }), el('small', { text: t('feed.tag.' + p.tag) + ' · ' + fmtTime(p.created_at) })])
      ]),
      p.thumb
        ? el('img', { class: 'post-img', src: p.thumb, alt: t('feed.tag.' + p.tag), loading: 'lazy', onclick: function () { viewPhoto(p.full); } })
        : el('p', { class: 'muted', text: t('feed.photo_gone') }),
      p.caption ? el('p', { class: 'post-caption', text: p.caption }) : null,
      p.status === 'hidden' ? note('info', t('feed.hidden')) : null,
      actions
    ]);
  }

  function screenFeed() {
    var f = state.feed || {};
    var s = f.state || {};
    var blocks = [header(s.team ? s.team.name : t('feed.title')), el('p', { class: 'muted', style: 'margin:0', text: t('feed.only_squad') })];

    if (!s.can_read) {
      blocks.push(note('info', t('feed.off.no_team')));
    } else {
      if (s.can_post) {
        blocks.push(el('button', { class: 'btn btn-lime', onclick: function () { state.flow.post = { tag: 'gym' }; go(screenFeedPost); } }, [icon('plus'), t('feed.new')]));
        blocks.push(el('small', { class: 'muted', text: t('feed.left_today', { n: s.left_today }) }));
      } else if (s.reason) {
        blocks.push(note('info', s.reason === 'media_off' ? t('media.off.' + s.media_reason) : t('feed.off.' + s.reason)));
      }
      if (!(f.posts || []).length) blocks.push(note('info', t('feed.empty')));
      (f.posts || []).forEach(function (p) { blocks.push(postCard(p)); });
      if (f.before) blocks.push(el('button', { class: 'btn btn-ghost', text: t('feed.more'), onclick: feedMore }));
    }

    blocks.push(navbar('feed'));
    return el('div', { class: 'stack' }, blocks);
  }

  /**
   * Фото уменьшается на телефоне: мобильный интернет в Ташкенте не должен
   * тащить 8 МБ оригинала. Браузер при этом сам ставит снимок по EXIF,
   * а метаданные в новый файл не попадают. Сервер всё равно пересожмёт.
   */
  function shrinkPhoto(file, maxSide) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var k = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * k));
        c.height = Math.max(1, Math.round(img.naturalHeight * k));
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        resolve(c.toDataURL('image/jpeg', 0.85));
      };
      img.onerror = function () {
        // Браузер не открыл формат — отправляем как есть, сервер решит.
        URL.revokeObjectURL(url);
        var fr = new FileReader();
        fr.onload = function () { resolve(fr.result); };
        fr.onerror = function () { resolve(null); };
        fr.readAsDataURL(file);
      };
      img.src = url;
    });
  }

  function screenFeedPost() {
    var s = (state.feed && state.feed.state) || {};
    var draft = state.flow.post || (state.flow.post = { tag: 'gym' });
    var msg = el('div', {});
    var preview = el('div', { class: 'post-preview' }, draft.image ? [el('img', { src: draft.image, alt: '' })] : []);
    var file = el('input', { type: 'file', accept: 'image/*', hidden: true });
    var pick = el('button', { type: 'button', class: 'btn btn-ghost', text: draft.image ? t('feed.change_photo') : t('feed.pick_photo'), onclick: function () { file.click(); } });

    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      if (!f) return;
      pick.disabled = true;
      shrinkPhoto(f, 1600).then(function (dataUrl) {
        pick.disabled = false;
        draft.image = dataUrl;
        preview.textContent = '';
        if (dataUrl) preview.appendChild(el('img', { src: dataUrl, alt: '' }));
        pick.textContent = t('feed.change_photo');
      });
    });

    var tags = el('div', { class: 'tagset' });
    (s.tags || ['plate', 'gym', 'walk', 'workout', 'other']).forEach(function (tag) {
      var b = choice(t('feed.tag.' + tag), draft.tag === tag, function () {
        draft.tag = tag;
        Array.prototype.forEach.call(tags.children, function (x) { x.classList.remove('opt-on'); });
        b.classList.add('opt-on');
      });
      tags.appendChild(b);
    });

    var caption = el('textarea', { rows: 2, maxlength: s.caption_max || 280, placeholder: t('feed.caption') });
    caption.value = draft.caption || '';
    caption.addEventListener('input', function () { draft.caption = caption.value; });

    var confirmBox = el('input', { type: 'checkbox', id: 'feed-confirm' });
    confirmBox.checked = !!draft.confirm;
    confirmBox.addEventListener('change', function () { draft.confirm = confirmBox.checked; });

    var submit = actionButton(t('feed.publish'), 'btn-primary');
    return el('div', { class: 'stack' }, [
      header(t('feed.new')),
      note('info', t('feed.rules')),
      file, pick, preview,
      tags,
      caption,
      el('label', { class: 'check', for: 'feed-confirm' }, [confirmBox, el('span', { text: t('feed.confirm') })]),
      msg,
      el('form', {
        class: 'stack',
        onsubmit: function (e) {
          e.preventDefault();
          msg.textContent = '';
          if (!draft.image) { msg.appendChild(note('error', t('feed.pick_photo'))); return; }
          busy(submit, true);
          api('POST', '/api/feed', { image: draft.image, tag: draft.tag, caption: draft.caption || '', confirm: !!draft.confirm }).then(function (r) {
            busy(submit, false);
            if (r.ok) { state.flow.post = null; return openFeed(); }
            if (r.safety) { state.flow.post = null; return render(screenSafety(r.safety)); }
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
        }
      }, [submit]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: openFeed })
    ]);
  }

  // ---------- созвоны сквада (срез 7) ----------

  function openCalls() {
    loading();
    api('GET', '/api/calls').then(function (r) {
      if (!r.ok) return loadHome();
      state.calls = r;
      go(screenCalls);
    });
  }

  function openLink(url) {
    if (!url) return;
    if (url.indexOf('https://t.me/') === 0) return openChat(url);
    if (TG && TG.openLink) TG.openLink(url);
    else window.open(url, '_blank', 'noopener');
  }

  function callCard(c, leader) {
    var msg = el('div', {});
    var answers = el('div', { class: 'opts opts-3' });
    ['yes', 'maybe', 'no'].forEach(function (a) {
      answers.appendChild(choice(t('calls.answer.' + a), c.my_answer === a, function () {
        api('POST', '/api/calls/' + c.id + '/rsvp', { answer: a }).then(function (r) { if (r.ok) openCalls(); });
      }));
    });
    return el('div', { class: 'card' + (c.live ? ' card-accent' : '') }, [
      el('div', { class: 'card-head' }, [
        el('div', { class: 'when' }, [el('b', { text: c.local_time }), el('span', { class: 'muted', text: fmtDate(c.local_date) + ' · ' + t('calls.minutes', { n: c.duration }) })]),
        c.live ? el('span', { class: 'pill pill-ink', text: t('calls.live') }) : bubble('video')
      ]),
      el('h2', { style: 'margin:0 0 6px', text: t('calls.topic.' + c.topic) }),
      c.note ? el('p', { class: 'muted', style: 'margin:0 0 8px', text: c.note }) : null,
      c.coming.length ? el('small', { class: 'muted', text: t('calls.coming', { names: c.coming.join(', ') }) }) : null,
      el('div', { class: 'stack', style: 'margin-top:10px' }, [
        answers,
        c.can_join
          ? el('button', { class: 'btn btn-tg', text: t('calls.join'), onclick: function () {
              api('POST', '/api/calls/' + c.id + '/join').then(function (r) {
                msg.textContent = '';
                if (!r.ok) { msg.appendChild(note('error', r.message || t('ui.network_error'))); return; }
                if (r.how) msg.appendChild(note('info', r.how));
                openLink(r.url);
              });
            } })
          : el('small', { class: 'muted', text: t('calls.join_later') }),
        msg,
        leader ? el('button', { class: 'link danger', text: t('calls.cancel'), onclick: function () {
          if (!window.confirm(t('calls.cancel') + '?')) return;
          api('POST', '/api/calls/' + c.id + '/cancel').then(function () { openCalls(); });
        } }) : null
      ])
    ]);
  }

  function screenCalls() {
    var d = state.calls || {};
    var blocks = [header(t('calls.title'))];
    if (!d.team) {
      blocks.push(note('info', t('calls.error.no_team')));
    } else {
      (d.upcoming || []).forEach(function (c) { blocks.push(callCard(c, d.i_am_leader)); });
      if (!(d.upcoming || []).length) {
        blocks.push(note('info', d.i_am_leader ? t('calls.none_leader') : t('calls.none')));
      }
      if (d.i_am_leader) {
        blocks.push(el('button', { class: 'btn btn-primary', text: t('calls.new'), onclick: function () { go(screenCallNew); } }));
      }
      if ((d.past || []).length) {
        blocks.push(el('div', { class: 'eyebrow', text: t('calls.past') }));
        blocks.push(el('div', { class: 'card' }, [el('div', { class: 'rows' }, d.past.map(function (p) {
          return row(fmtDate(p.local_date) + ' · ' + t('calls.topic.' + p.topic), t('calls.joined', { n: p.joined }));
        }))]));
      }
    }
    blocks.push(navbar(hasModule('feed.open') ? 'squad' : 'calls'));
    return el('div', { class: 'stack' }, blocks);
  }

  function screenCallNew() {
    var d = state.calls || {};
    var msg = el('div', {});
    var today = new Date();
    function iso(x) { return x.getFullYear() + '-' + ('0' + (x.getMonth() + 1)).slice(-2) + '-' + ('0' + x.getDate()).slice(-2); }
    var next = new Date(today.getTime() + 86400000);
    var date = field(t('calls.date'), { type: 'date', min: iso(today), value: iso(next) });
    var time = field(t('calls.time'), { type: 'time', value: '20:00', step: 300 });
    var noteF = field(t('calls.note'), { type: 'text', maxlength: 200 });
    var picked = { duration: 30, topic: 'week' };

    function group(values, key, label) {
      var box = el('div', { class: 'tagset' });
      values.forEach(function (v) {
        var b = choice(label(v), picked[key] === v, function () {
          picked[key] = v;
          Array.prototype.forEach.call(box.children, function (x) { x.classList.remove('opt-on'); });
          b.classList.add('opt-on');
        });
        box.appendChild(b);
      });
      return box;
    }

    var submit = actionButton(t('calls.create'), 'btn-primary');
    return el('div', { class: 'stack' }, [
      header(t('calls.new')),
      el('form', {
        class: 'stack',
        onsubmit: function (e) {
          e.preventDefault();
          busy(submit, true);
          api('POST', '/api/calls', {
            date: date.input.value, time: time.input.value, duration: picked.duration, topic: picked.topic, note: noteF.input.value
          }).then(function (r) {
            busy(submit, false);
            if (r.ok) return openCalls();
            msg.textContent = '';
            msg.appendChild(note('error', r.message || t('ui.network_error')));
          });
        }
      }, [
        date.wrap, time.wrap,
        el('div', { class: 'eyebrow', text: t('calls.topic') }),
        group(d.topics || ['week', 'support', 'plan', 'free'], 'topic', function (v) { return t('calls.topic.' + v); }),
        el('div', { class: 'eyebrow', text: t('calls.duration') }),
        group(d.durations || [15, 30, 45, 60, 90], 'duration', function (v) { return t('calls.minutes', { n: v }); }),
        noteF.wrap,
        msg,
        submit
      ]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: openCalls })
    ]);
  }

  // ---------- модератор: жалобы на фото ----------

  function openFeedReports() {
    api('GET', '/api/admin/feed/reports').then(function (r) { state.adminFeed = r.ok ? r.reports : []; go(screenFeedReports); });
  }

  function screenFeedReports() {
    var list = state.adminFeed || [];
    var blocks = [header(t('feed.admin.title')), adminTabs('feed')];
    if (!list.length) blocks.push(note('ok', t('feed.admin.none')));
    list.forEach(function (q) {
      var reasons = Object.keys(q.reasons || {}).map(function (k) { return t('feed.reason.' + k) + (q.reasons[k] > 1 ? ' × ' + q.reasons[k] : ''); });
      blocks.push(el('div', { class: 'card post' }, [
        el('div', { class: 'eyebrow', text: q.author + ' · ' + t('feed.admin.squad', { n: q.team_id }) + (q.status === 'hidden' ? ' · ' + t('feed.admin.hidden') : '') }),
        q.full ? el('img', { class: 'post-img', src: q.full, alt: '', onclick: function () { viewPhoto(q.full); } }) : null,
        q.caption ? el('p', { class: 'post-caption', text: q.caption }) : null,
        el('p', { class: 'danger', style: 'margin:0 0 10px;font-size:14px', text: reasons.join(' · ') }),
        el('div', { class: 'opts opts-2' }, [
          el('button', { class: 'opt', text: t('feed.admin.restore'), onclick: function () { adminPost('/api/admin/feed/' + q.id + '/restore', {}, openFeedReports); } }),
          el('button', { class: 'opt danger', text: t('feed.admin.remove'), onclick: function () {
            if (window.confirm(t('feed.admin.remove') + '?')) adminPost('/api/admin/feed/' + q.id + '/remove', {}, openFeedReports);
          } })
        ])
      ]));
    });
    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } }));
    return el('div', { class: 'stack' }, blocks);
  }

  // ---------- модератор: вкладки ----------

  function adminTabs(active) {
    var tabs = [['squads', openAdmin], ['payments', openPayments], ['people', openPeople], ['metrics', openMetrics], ['system', openSystem]];
    // Вкладка жалоб на фото — только если модуль ленты включён.
    if (hasModule('admin.tab.feed')) tabs.splice(1, 0, ['feed', openFeedReports]);
    return el('div', { class: 'tabs' }, tabs.map(function (tb) {
      // Обёртка: иначе событие клика уйдёт в функцию первым аргументом.
      return el('button', { class: 'tab' + (tb[0] === active ? ' tab-on' : ''), text: t('admin.tab.' + tb[0]), onclick: function () { tb[1](); } });
    }));
  }

  function openPayments() {
    Promise.all([api('GET', '/api/admin/billing/payments'), api('GET', '/api/admin/billing/promos')]).then(function (r) {
      state.adminPay = r[0].ok ? r[0] : {};
      state.adminPromos = r[1].ok ? r[1].promos : [];
      go(screenPayments);
    });
  }

  function screenPayments() {
    var d = state.adminPay || {};
    var code = field(t('billing.admin.code'), { type: 'text', autocapitalize: 'characters', maxlength: 24 });
    var value = field(t('billing.admin.value'), { type: 'number', inputmode: 'numeric' });
    var uses = field(t('billing.admin.max_uses'), { type: 'number', inputmode: 'numeric', value: '1' });
    var kind = el('select', { class: 'mini' }, ['free_season', 'percent', 'fixed'].map(function (k) { return el('option', { value: k, text: t('billing.admin.kind.' + k) }); }));
    return el('div', { class: 'stack' }, [
      header(t('admin.title')), adminTabs('payments'),
      note('info', t('billing.admin.revenue', { sum: String(d.revenue || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ' '), n: d.active || 0 })),
      el('div', { class: 'eyebrow', text: t('billing.admin.pending') }),
      (d.payments || []).length ? el('div', { class: 'stack' }, d.payments.map(function (p) {
        return el('div', { class: 'card' + (p.marked_paid ? ' card-accent' : '') }, [
          el('div', { class: 'eyebrow', text: p.name + (p.phone ? ' · ' + p.phone : '') + (p.marked_paid ? ' · ' + t('billing.admin.marked') : '') }),
          el('div', { class: 'rows' }, [
            row(t('billing.tariff.' + p.tariff) + (p.installment_no ? ' #' + p.installment_no : ''), money(p.amount)),
            row(t('billing.pay.code'), p.code),
            p.payer_note ? row(t('billing.pay.note'), p.payer_note) : null,
            p.promo_code ? row(t('billing.promo'), p.promo_code) : null
          ].filter(Boolean)),
          el('div', { class: 'opts opts-2', style: 'margin-top:10px' }, [
            choice(t('billing.admin.confirm'), false, function () { adminPost('/api/admin/billing/payments/' + p.id + '/confirm', {}, openPayments); }),
            choice(t('billing.admin.reject'), false, function () { adminPost('/api/admin/billing/payments/' + p.id + '/reject', { reason: '' }, openPayments); })
          ])
        ]);
      })) : note('ok', t('billing.admin.none')),
      el('div', { class: 'eyebrow', text: t('billing.admin.promos') }),
      el('div', { class: 'card' }, [el('div', { class: 'rows' }, (state.adminPromos || []).map(function (pr) {
        return el('div', { class: 'row' }, [
          el('span', {}, [pr.code, el('small', { class: 'muted', text: ' · ' + t('billing.admin.kind.' + pr.kind) + (pr.value ? ' ' + pr.value : '') + ' · ' + pr.used + '/' + pr.max_uses })]),
          Number(pr.active) ? el('button', { class: 'link', text: t('billing.admin.disable'), onclick: function () { adminPost('/api/admin/billing/promos/' + pr.code + '/disable', {}, openPayments); } }) : el('span', { class: 'muted', text: '—' })
        ]);
      }))]),
      el('div', { class: 'card stack' }, [
        el('div', { class: 'eyebrow', text: t('billing.admin.new_promo') }),
        code.wrap, el('div', { class: 'field' }, [el('label', { text: t('billing.admin.kind') }), kind]), value.wrap, uses.wrap,
        el('button', { class: 'btn btn-ghost', text: t('billing.admin.create'), onclick: function () {
          adminPost('/api/admin/billing/promos', { code: code.input.value, kind: kind.value, value: value.input.value, max_uses: uses.input.value }, openPayments);
        } })
      ]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ]);
  }

  function openPeople(q) {
    api('GET', '/api/admin/users?q=' + encodeURIComponent(q || '')).then(function (r) {
      state.adminPeople = r.ok ? r.users : [];
      state.adminPeopleQ = q || '';
      go(screenPeople);
    });
  }

  function screenPeople() {
    var q = field(t('admin.search'), { type: 'search', value: state.adminPeopleQ || '' });
    var isAdmin = state.user && state.user.role === 'admin';
    return el('div', { class: 'stack' }, [
      header(t('admin.title')), adminTabs('people'),
      el('form', { class: 'stack', onsubmit: function (e) { e.preventDefault(); openPeople(q.input.value); } }, [q.wrap]),
      el('div', { class: 'card' }, [el('div', { class: 'rows' }, (state.adminPeople || []).map(function (u) {
        var actions = [];
        if (isAdmin && u.id !== state.user.id) {
          ['user', 'moderator', 'admin'].forEach(function (rl) {
            if (rl !== u.role) actions.push(el('button', { class: 'link', text: t('admin.make', { role: t('admin.role.' + rl) }), onclick: function () {
              adminPost('/api/admin/users/' + u.id + '/role', { role: rl }, function () { openPeople(state.adminPeopleQ); });
            } }));
          });
        }
        if (u.id !== (state.user && state.user.id)) {
          actions.push(el('button', { class: 'link', text: u.status === 'blocked' ? t('admin.unblock') : t('admin.block'), onclick: function () {
            adminPost('/api/admin/users/' + u.id + '/' + (u.status === 'blocked' ? 'unblock' : 'block'), {}, function () { openPeople(state.adminPeopleQ); });
          } }));
        }
        return el('div', { class: 'row', style: 'flex-wrap:wrap' }, [
          el('span', {}, [(u.name || '#' + u.id), el('small', { class: 'muted', text: ' · ' + (u.phone || (u.tg ? '@' + u.tg : '')) + ' · ' + t('admin.role.' + u.role) + (u.status === 'blocked' ? ' · ' + t('admin.blocked') : '') })]),
          el('span', { class: 'actions' }, actions)
        ]);
      }))]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ]);
  }

  function openMetrics() {
    api('GET', '/api/admin/metrics').then(function (r) { state.adminMetrics = r.ok ? r : {}; go(screenMetrics); });
  }

  /** Каждая метрика — рядом со своей целью (§ 13). Ниже цели — подсказка, что делать. */
  function screenMetrics() {
    var d = state.adminMetrics || {};
    var groups = {};
    (d.metrics || []).forEach(function (m) { (groups[m.group] = groups[m.group] || []).push(m); });
    var blocks = [header(t('metric.title')), adminTabs('metrics')];
    Object.keys(groups).forEach(function (g) {
      blocks.push(el('div', { class: 'eyebrow', text: t('metric.group.' + g) }));
      blocks.push(el('div', { class: 'card' }, [el('div', { class: 'rows' }, groups[g].map(function (m) {
        var val = m.value === null ? t('metric.no_data') : (m.value + (m.unit === '%' ? '%' : m.unit ? ' ' + m.unit : ''));
        var target = (m.better === 'lower' ? '≤ ' : '≥ ') + m.target + (m.unit === '%' ? '%' : m.unit ? ' ' + m.unit : '');
        return el('div', { class: 'metric metric-' + m.status }, [
          el('div', { class: 'row' }, [el('span', { text: m.title }), el('span', { class: 'mval', text: val })]),
          el('small', { class: 'muted', text: t('metric.target') + ' ' + target + (m.status === 'bad' && m.hint ? ' — ' + m.hint : '') })
        ]);
      }))]));
    });
    if ((d.cohorts || []).length) {
      blocks.push(el('div', { class: 'eyebrow', text: t('metric.cohorts') }));
      blocks.push(el('div', { class: 'card' }, [el('div', { class: 'rows' }, d.cohorts.map(function (c) {
        return row(c.week, c.size + ' ' + t('metric.cohort.size') + ' · ' + c.onboarded + ' ' + t('metric.cohort.onboarded') + ' · ' + c.paid + ' ' + t('metric.cohort.paid') + ' · D7 ' + c.d7 + '/' + c.d7_base);
      }))]));
    }
    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } }));
    return el('div', { class: 'stack' }, blocks);
  }

  function openSystem() {
    api('GET', '/api/admin/audit').then(function (r) { state.adminAudit = r.ok ? r.audit : []; go(screenSystem); });
  }

  function screenSystem() {
    var msg = el('div', {});
    var isAdmin = state.user && state.user.role === 'admin';
    return el('div', { class: 'stack' }, [
      header(t('admin.title')), adminTabs('system'),
      el('button', { class: 'btn btn-ghost', text: t('admin.health'), onclick: function () { window.open('/health', '_blank', 'noopener'); } }),
      isAdmin ? el('button', { class: 'btn btn-ghost', text: t('admin.migrate'), onclick: function () {
        api('POST', '/api/admin/migrate').then(function (r) {
          msg.textContent = '';
          msg.appendChild(note(r.ok ? 'ok' : 'error', r.ok ? t('admin.migrated', { n: (r.applied || []).length }) : (r.errors || []).join('; ')));
        });
      } }) : null,
      msg,
      el('div', { class: 'eyebrow', text: t('admin.audit') }),
      el('div', { class: 'card' }, [el('div', { class: 'rows' }, (state.adminAudit || []).map(function (a) {
        return row(String(a.created_at).slice(0, 16).replace('T', ' ') + ' · ' + a.actor, a.module + '.' + a.action + (a.target_id ? ' → #' + a.target_id : ''));
      }))]),
      el('div', { class: 'spacer' }),
      el('button', { class: 'btn btn-quiet', text: t('ui.back'), onclick: function () { loadHome(); } })
    ].filter(Boolean));
  }

  // ---------- переходы ----------

  function go(screen) {
    render(screen());
    if (TG && TG.BackButton) {
      if (screen === screenWelcome || screen === screenHome) TG.BackButton.hide();
      else TG.BackButton.show();
    }
  }

  function signedIn(response) {
    state.user = response.user;
    state.token = response.token || state.token;
    if (state.token) storage('token', state.token);
    // Пришёл по приглашению — отдаём код один раз и забываем.
    var ref = storage('ref');
    if (ref) {
      storage('ref', null);
      api('POST', '/api/billing/referral', { code: ref }).then(loadHome);
      return;
    }
    loadHome();
  }

  /** Код приглашения: ?ref=КОД в ссылке или start_param «ref_КОД» в Telegram. */
  function captureReferral() {
    var m = /[?&]ref=([A-Za-z0-9]{4,12})/.exec(location.search);
    var code = m ? m[1] : null;
    if (!code && TG && TG.initDataUnsafe && TG.initDataUnsafe.start_param) {
      var sp = /^ref_([A-Za-z0-9]{4,12})$/.exec(TG.initDataUnsafe.start_param);
      code = sp ? sp[1] : null;
    }
    if (code) storage('ref', code.toUpperCase());
  }

  function logout() {
    api('POST', '/api/auth/logout').then(function () {
      state.user = null; state.token = null; state.plan = null; state.today = null;
      storage('token', null);
      go(screenWelcome);
    });
  }

  /** Куда идти после входа — решает состояние онбординга. */
  function loadHome() {
    render(el('div', { class: 'stack' }, [header(), note('info', t('ui.loading'))]));

    var need = [api('GET', '/api/onboarding/state')];
    if (!state.schema) need.push(api('GET', '/api/onboarding/questions'));

    Promise.all(need).then(function (res) {
      state.onboarding = res[0];
      if (res[1] && res[1].questions) state.schema = res[1];

      var step = res[0].step;
      if (step === 'questions') {
        state.qIndex = 0;
        return go(state.answers.consent_health ? screenQuestion : screenConsent);
      }
      if (step === 'screening') return go(screenScreening);
      if (step === 'rejected') {
        state.flow.reject = { message: t('onboarding.reject.' + res[0].reason) };
        return go(screenRejected);
      }
      if (step === 'zero_cycle') return go(screenZeroCycle);

      return Promise.all([
        api('GET', '/api/checkin/today'),
        api('GET', '/api/plan'),
        api('GET', '/api/me/progress'),
        api('GET', '/api/squad'),
        api('GET', '/api/coach/state'),
        api('GET', '/api/wearable/days?days=7'),
        api('GET', '/api/billing')
      ]).then(function (p) {
        // Чек-ин отдаёт и действие дня, и неделю — отдельный запрос
        // за планом на сегодня не нужен.
        state.checkin  = p[0] && p[0].ok ? p[0] : null;
        state.today    = state.checkin ? state.checkin.plan : null;
        state.plan     = (p[1] && p[1].plan) || null;
        state.progress = (p[2] && p[2].progress) || null;
        // Модуль сквадов выключен — маршрута нет (404), карточки просто не будет.
        state.squad    = p[3] && p[3].ok ? p[3] : null;
        // Выключенный модуль — 404, и карточка просто не появляется.
        state.coach    = p[4] && p[4].ok ? p[4] : null;
        state.wear     = p[5] && p[5].ok ? p[5] : null;
        state.billing  = p[6] && p[6].ok ? p[6] : null;
        go(screenHome);
      });
    });
  }

  function refreshOnboarding() { loadHome(); }

  function route() {
    if (state.user) return loadHome();
    go(screenWelcome);
  }

  function loginWithTelegram(interactive) {
    if (!TG || !TG.initData) return Promise.resolve(false);
    return api('POST', '/api/auth/telegram', { init_data: TG.initData }).then(function (r) {
      if (r.ok && r.token) { signedIn(r); return true; }
      if (interactive) {
        render(el('div', { class: 'stack' }, [
          header(), note('error', r.message || t('ui.network_error')),
          el('button', { class: 'btn btn-ghost', text: t('ui.back'), onclick: function () { go(screenWelcome); } })
        ]));
      }
      return false;
    });
  }

  function loadStrings() {
    return api('GET', '/api/i18n?lang=' + state.lang).then(function (r) {
      if (r.strings) state.strings = r.strings;
      if (r.lang) state.lang = r.lang;
    });
  }

  // ---------- запуск ----------

  function boot() {
    applyTheme();
    if (TG && TG.onEvent) TG.onEvent('themeChanged', applyTheme);
    if (TG) {
      TG.ready();
      TG.expand();
      if (TG.BackButton) TG.BackButton.onClick(function () { route(); });
    }

    captureReferral();
    var saved = storage('lang');
    if (saved === 'ru' || saved === 'uz') state.lang = saved;
    state.token = storage('token');

    loadStrings()
      .then(function () {
        if (TG && TG.initData && !state.token) return loginWithTelegram(false);
        return false;
      })
      .then(function (done) {
        if (done) return;
        if (!state.token) return go(screenWelcome);
        return api('GET', '/api/me').then(function (r) {
          if (r.ok && r.user) state.user = r.user;
          // Выходим только если сервер сказал «сессии нет». Обрыв связи
          // (метро, лифт, слабый 3G) — не повод разлогинивать человека.
          else if (r.__status === 401) { state.token = null; storage('token', null); }
          else {
            return render(el('div', { class: 'stack' }, [
              header(), note('error', t('ui.network_error')),
              el('button', { class: 'btn btn-primary', text: t('ui.retry'), onclick: function () { location.reload(); } })
            ]));
          }
          route();
        });
      });
  }

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/assets/sw.js').catch(function () {});
    });
  }

  boot();
})();
