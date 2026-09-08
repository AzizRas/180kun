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
    progress: null
  };

  // ---------- вспомогательное ----------

  function t(key, vars) {
    var s = state.strings[key] || key;
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

  // ---------- сборка экрана ----------

  var host = document.getElementById('app');

  function render(node) {
    host.textContent = '';
    host.appendChild(node);
    if (!TG) {
      var first = host.querySelector('input');
      if (first) first.focus();
    }
    window.scrollTo(0, 0);
  }

  function header(subtitle) {
    return el('div', { class: 'top' }, [
      el('div', {}, [
        el('div', { class: 'mark' }, [document.createTextNode('LEVEL '), el('b', { text: '180' })]),
        subtitle ? el('div', { class: 'muted', text: subtitle }) : null
      ]),
      langSwitch()
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
      el('div', { class: 'stack' }, [
        el('h1', { text: t('ui.app_name') }),
        el('p', { class: 'lead', text: t('ui.tagline') })
      ]),
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

  function screenHome() {
    var c = state.checkin || {};
    var plan = state.plan || {};
    var meta = plan.meta || {};
    var pl = c.plan || state.today || {};
    var day = pl.day || plan.current_day || 0;
    var pct = Math.max(0, Math.min(100, Math.round(day / 180 * 100)));
    var quiet = c.state === 'recovery' || c.state === 'dormant';

    var blocks = [header()];

    // Состояние, если человек выпадал
    if (c.state && c.state !== 'active' && c.state_hint) {
      blocks.push(el('div', { class: 'card card-quiet' }, [
        el('div', { class: 'eyebrow', text: c.state_title }),
        el('p', { style: 'margin:0', text: c.state_hint })
      ]));
    }

    // Полоса сезона
    blocks.push(el('div', { class: 'card' }, [
      el('div', { class: 'daybar' }, [
        el('div', { class: 'n', text: String(Math.max(0, day)) }),
        el('div', { class: 'of', text: t('plan.of_180') })
      ]),
      el('div', { class: 'track' }, [el('i', { style: 'width:' + pct + '%' })]),
      pl.chapter_title
        ? el('p', { class: 'muted', style: 'margin:12px 0 0', text: pl.chapter_title + (pl.deload ? ' · ' + t('plan.deload') : '') })
        : null
    ]));

    // Действие на сегодня и кнопка отметки
    if (pl.action) {
      var a = pl.action;
      var title = a.target ? String(a.title).split('{target}').join(String(a.target)) : a.title;
      var card = el('div', { class: 'card card-accent' }, [
        el('div', { class: 'eyebrow', text: t('plan.today') }),
        el('h2', { text: title }),
        el('p', { class: 'muted', text: a.hint || '' })
      ]);
      blocks.push(card);

      blocks.push(c.recorded
        ? el('div', { class: 'stack' }, [
            note('ok', t('checkin.done_today')),
            el('button', { class: 'btn btn-ghost', text: t('checkin.change'), onclick: function () { go(screenCheckin); } })
          ])
        : actionButton(t('checkin.save'), 'btn-primary', function () { go(screenCheckin); }));
    } else if (pl.finished) {
      blocks.push(el('div', { class: 'card' }, [el('h2', { text: t('plan.finished') })]));
    }

    // Неделя
    if (c.week) {
      var left = Math.max(0, c.week.norm_days - c.week.done_days);
      blocks.push(el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('checkin.week') }),
        weekStrip(c.week),
        el('div', { class: 'rows' }, [
          row(t('checkin.week_progress', { done: c.week.done_days, norm: c.week.norm_days }),
              c.week.kept ? t('checkin.week_kept') : t('checkin.week_left', { n: left })),
          c.week.excused ? row(t('checkin.excused', { n: c.week.excused }), String(c.week.excused)) : null,
          row(t('checkin.streak'), String(c.streak || 0)),
          row(t('checkin.shields'), String(c.shields != null ? c.shields : 0))
        ].filter(Boolean)),
        el('button', {
          class: 'btn btn-quiet', style: 'margin-top:6px',
          text: t('checkin.mark_event'), onclick: function () { go(screenEvent); }
        })
      ]));
    }

    // Очки — скрыты в режиме восстановления: соревнование сейчас не помогает
    if (state.progress && !quiet) {
      blocks.push(el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('gami.level') }),
        el('div', { class: 'daybar' }, [
          el('div', { class: 'n', text: String(state.progress.level) }),
          el('div', { class: 'of', text: state.progress.xp + ' ' + t('gami.xp') })
        ]),
        el('p', { class: 'muted', style: 'margin:10px 0 0', text: t('gami.to_next', { n: state.progress.xp_to_next }) })
      ]));
    }

    // Итог плана
    if (meta.weight_start) {
      blocks.push(el('div', { class: 'card' }, [
        el('div', { class: 'eyebrow', text: t('plan.title') }),
        el('div', { class: 'rows' }, [
          row(t('ui.from_to'), meta.weight_start + ' → ' + meta.weight_final + ' ' + t('ui.kg')),
          row(t('ui.steps'), meta.steps_start + ' → ' + meta.steps_final),
          row(t('ui.tier'), plan.tier ? t('tier.' + plan.tier) : '—')
        ])
      ]));
      if (plan.limited) blocks.push(note('info', t('plan.limited_note')));
      blocks.push(el('button', {
        class: 'btn btn-ghost', text: t('ui.show_chapters'),
        onclick: function () { go(screenChapters); }
      }));
    }

    if (state.user && state.user.needs_phone) blocks.push(note('info', t('ui.attach_phone')));

    blocks.push(el('div', { class: 'spacer' }));
    blocks.push(el('button', { class: 'btn btn-quiet', text: t('ui.logout'), onclick: logout }));

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
            if (r.returned) {
              render(el('div', { class: 'stack' }, [
                header(),
                el('div', { class: 'card card-accent center' }, [
                  el('div', { class: 'tier', text: '+' + 100 }),
                  el('h2', { text: t('checkin.welcome_back') }),
                  el('p', { class: 'muted', text: t('gami.comeback_note') })
                ]),
                el('div', { class: 'spacer' }),
                actionButton(t('ui.next'), 'btn-primary', function () { loadHome(); })
              ]));
              return;
            }
            return loadHome();
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

    var actionTitle = c.plan && c.plan.action ? c.plan.action.title : '';
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
    loadHome();
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
      if (step === 'questions') { state.qIndex = 0; return go(screenQuestion); }
      if (step === 'screening') return go(screenScreening);
      if (step === 'rejected') {
        state.flow.reject = { message: t('onboarding.reject.' + res[0].reason) };
        return go(screenRejected);
      }
      if (step === 'zero_cycle') return go(screenZeroCycle);

      return Promise.all([
        api('GET', '/api/checkin/today'),
        api('GET', '/api/plan'),
        api('GET', '/api/me/progress')
      ]).then(function (p) {
        // Чек-ин отдаёт и действие дня, и неделю — отдельный запрос
        // за планом на сегодня не нужен.
        state.checkin  = p[0] && p[0].ok ? p[0] : null;
        state.today    = state.checkin ? state.checkin.plan : null;
        state.plan     = (p[1] && p[1].plan) || null;
        state.progress = (p[2] && p[2].progress) || null;
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
    if (TG) {
      TG.ready();
      TG.expand();
      if (TG.BackButton) TG.BackButton.onClick(function () { route(); });
    }

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
          else { state.token = null; storage('token', null); }
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
