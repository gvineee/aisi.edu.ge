/**
 * Georgian dates where the browser has no Georgian locale data.
 *
 * Some browsers (and stripped-down Chromium builds) ship without ka-GE,
 * and then `toLocaleDateString('ka-GE', …)` silently prints English
 * ("October 1, 2026"). When — and only when — that is the case, the three
 * Date#toLocale* methods are taught Georgian for the `ka` locales; every
 * other locale, and browsers that do have ka-GE, are left untouched.
 */
const MONTHS = [
    'იანვარი',
    'თებერვალი',
    'მარტი',
    'აპრილი',
    'მაისი',
    'ივნისი',
    'ივლისი',
    'აგვისტო',
    'სექტემბერი',
    'ოქტომბერი',
    'ნოემბერი',
    'დეკემბერი',
];
const MONTHS_SHORT = [
    'იან',
    'თებ',
    'მარ',
    'აპრ',
    'მაი',
    'ივნ',
    'ივლ',
    'აგვ',
    'სექ',
    'ოქტ',
    'ნოე',
    'დეკ',
];
const WEEKDAYS = [
    'კვირა',
    'ორშაბათი',
    'სამშაბათი',
    'ოთხშაბათი',
    'ხუთშაბათი',
    'პარასკევი',
    'შაბათი',
];
const WEEKDAYS_SHORT = ['კვი', 'ორშ', 'სამ', 'ოთხ', 'ხუთ', 'პარ', 'შაბ'];

type Kind = 'date' | 'time' | 'both';

const isGeorgian = (locales: Intl.LocalesArgument): boolean => {
    const first = Array.isArray(locales) ? locales[0] : locales;

    return typeof first === 'string' && /^ka(-|$)/i.test(first);
};

const hasDateFields = (o: Intl.DateTimeFormatOptions) =>
    o.year !== undefined ||
    o.month !== undefined ||
    o.day !== undefined ||
    o.weekday !== undefined;
const hasTimeFields = (o: Intl.DateTimeFormatOptions) =>
    o.hour !== undefined || o.minute !== undefined || o.second !== undefined;

export function formatGeorgian(
    date: Date,
    options: Intl.DateTimeFormatOptions = {},
    kind: Kind = 'both',
): string {
    if (Number.isNaN(date.getTime())) {
        return 'Invalid Date';
    }

    let o: Intl.DateTimeFormatOptions = { ...options };

    if (o.dateStyle !== undefined || o.timeStyle !== undefined) {
        o = {
            ...(o.dateStyle
                ? {
                      year: 'numeric',
                      month: o.dateStyle === 'short' ? '2-digit' : 'long',
                      day: 'numeric',
                  }
                : {}),
            ...(o.timeStyle ? { hour: '2-digit', minute: '2-digit' } : {}),
            timeZone: o.timeZone,
        };
    }

    if (!hasDateFields(o) && !hasTimeFields(o)) {
        o = {
            ...o,
            ...(kind !== 'time'
                ? { year: 'numeric', month: '2-digit', day: '2-digit' }
                : {}),
            ...(kind !== 'date'
                ? { hour: '2-digit', minute: '2-digit', second: '2-digit' }
                : {}),
        };
    }

    const parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: o.timeZone,
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric',
        hourCycle: 'h23',
    }).formatToParts(date);
    const get = (type: string) =>
        Number(parts.find((p) => p.type === type)?.value ?? 0);
    const year = get('year');
    const month = get('month');
    const day = get('day');
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
    const pad = (n: number) => String(n).padStart(2, '0');

    const dateText = (() => {
        if (!hasDateFields(o)) return '';
        const wd =
            o.weekday === undefined
                ? ''
                : (o.weekday === 'long' ? WEEKDAYS : WEEKDAYS_SHORT)[weekday];
        const textual =
            o.month === 'long' || o.month === 'short' || o.month === 'narrow';
        let text: string;

        if (textual) {
            const name = (o.month === 'long' ? MONTHS : MONTHS_SHORT)[
                month - 1
            ];
            text = [o.day !== undefined ? String(day) : '', name]
                .filter(Boolean)
                .join(' ');
            if (o.year !== undefined)
                text += o.day !== undefined ? `, ${year}` : ` ${year}`;
        } else {
            text = [
                o.day !== undefined ? pad(day) : '',
                o.month !== undefined ? pad(month) : '',
                o.year !== undefined ? String(year) : '',
            ]
                .filter(Boolean)
                .join('.');
        }

        return [wd, text].filter(Boolean).join(', ');
    })();

    const timeText = hasTimeFields(o)
        ? [
              pad(get('hour')),
              o.minute !== undefined ? pad(get('minute')) : '00',
              ...(o.second !== undefined ? [pad(get('second'))] : []),
          ].join(':')
        : '';

    return [dateText, timeText].filter(Boolean).join(', ');
}

export function installGeorgianDates(): void {
    if (
        typeof Intl === 'undefined' ||
        Intl.DateTimeFormat.supportedLocalesOf(['ka']).length > 0
    ) {
        return;
    }

    // The browser's own methods, kept to be called with .call(this).
    /* eslint-disable typescript/unbound-method */
    const original = {
        date: Date.prototype.toLocaleDateString,
        time: Date.prototype.toLocaleTimeString,
        both: Date.prototype.toLocaleString,
    };
    /* eslint-enable typescript/unbound-method */

    Date.prototype.toLocaleDateString = function (
        locales?: Intl.LocalesArgument,
        options?: Intl.DateTimeFormatOptions,
    ) {
        return isGeorgian(locales)
            ? formatGeorgian(this, options, 'date')
            : original.date.call(this, locales, options);
    };
    Date.prototype.toLocaleTimeString = function (
        locales?: Intl.LocalesArgument,
        options?: Intl.DateTimeFormatOptions,
    ) {
        return isGeorgian(locales)
            ? formatGeorgian(this, options, 'time')
            : original.time.call(this, locales, options);
    };
    Date.prototype.toLocaleString = function (
        locales?: Intl.LocalesArgument,
        options?: Intl.DateTimeFormatOptions,
    ) {
        return isGeorgian(locales)
            ? formatGeorgian(this, options, 'both')
            : original.both.call(this, locales, options);
    };
}
