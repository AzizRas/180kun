<?php
return [
    // Ошибки
    'checkin.bad_done'        => 'Выберите, как прошёл день.',
    'checkin.reason_required' => 'Отметьте, что помешало. Это нужно, чтобы завтра дать другой план.',
    'checkin.too_old'         => 'Отметиться можно за сегодня и за вчера. Более ранние дни закрыты.',
    'checkin.in_future'       => 'Этот день ещё не наступил.',
    'checkin.bad_event_type'  => 'Такого события нет в списке.',
    'checkin.event_too_long'  => 'Событие длиннее {max_days} дней — это уже смена обстоятельств, план надо пересобрать.',

    // Подтверждения
    'checkin.saved'         => 'Записано.',
    'checkin.welcome_back'  => 'С возвращением. Начинаем с сегодняшнего дня, ничего не потеряно.',
    'checkin.event_saved'   => 'Отмечено. Эти дни не сломают неделю.',

    // Экран чек-ина
    'checkin.title'         => 'Как прошёл день',
    'checkin.done.yes'      => 'Сделал',
    'checkin.done.partial'  => 'Частично',
    'checkin.done.no'       => 'Не вышло',
    'checkin.energy'        => 'Энергия',
    'checkin.mood'          => 'Настроение',
    'checkin.why'           => 'Что помешало',
    'checkin.reason.no_time'    => 'Не было времени',
    'checkin.reason.tired'      => 'Устал',
    'checkin.reason.sick'       => 'Болел',
    'checkin.reason.event'      => 'Было событие',
    'checkin.reason.forgot'     => 'Забыл',
    'checkin.reason.didnt_want' => 'Не захотел',
    'checkin.value'         => 'Сколько получилось',
    'checkin.note'          => 'Коротко, одной строкой',
    'checkin.save'          => 'Записать день',
    'checkin.change'        => 'Изменить отметку',
    'checkin.done_today'    => 'День отмечен',

    // Неделя
    'checkin.week'          => 'Неделя',
    'checkin.week_progress' => '{done} из {norm}',
    'checkin.week_kept'     => 'Норма недели выполнена',
    'checkin.week_left'     => 'Осталось дней до нормы: {n}',
    'checkin.streak'        => 'Недель подряд',
    'checkin.shields'       => 'Щитов в этом месяце',
    'checkin.shield_hint'   => 'Щит спасает неделю, где не хватило одного дня.',
    'checkin.excused'       => 'Дней отмечено как событие: {n}',

    // События
    'checkin.mark_event'    => 'Отметить событие',
    'checkin.event.toy'       => 'Тўй',
    'checkin.event.illness'   => 'Болезнь',
    'checkin.event.trip'      => 'Поездка',
    'checkin.event.fasting'   => 'Пост',
    'checkin.event.vacation'  => 'Отпуск',
    'checkin.event_from'      => 'С какого дня',
    'checkin.event_to'        => 'По какой день',
    'checkin.event_intro'     => 'Отметьте заранее — и эти дни не будут считаться пропуском.',

    // Состояния
    'state.active'         => 'В графике',
    'state.active.hint'    => '',
    'state.attention'      => 'Пауза',
    'state.attention.hint' => 'Пары дней не было. Сегодня достаточно уменьшенной версии — она засчитывается полностью.',
    'state.recovery'       => 'Восстановление',
    'state.recovery.hint'  => 'Возвращаемся с одного маленького действия. Рейтинги пока скрыты — они сейчас не помогут.',
    'state.dormant'        => 'Сезон на паузе',
    'state.dormant.hint'   => 'Ваше место сохранено. План перестроится с того дня, когда вы вернётесь.',
];
