import { usePage } from '@inertiajs/react';

/**
 * Interface text of the public site per language. Page and news content
 * comes translated from the CMS; this covers only the chrome around it.
 * Georgian is the school's own language and the fallback for anything a
 * dictionary lacks.
 */
const ka = {
    menu: 'მენიუ',
    home: 'მთავარი',
    myPortal: 'ჩემი {name}',
    admissions: 'მიღება',
    documents: 'დოკუმენტები',
    journal: 'ელექტრონული ჟურნალი',
    madeByBefore: 'დამზადებულია ',
    madeByAfter: '-ში, 2026',
    news: 'ამბები',
    newsTitle: 'სიახლეები',
    newsEmpty: 'სიახლეები მალე გამოქვეყნდება.',
    language: 'ენა',
    inOriginal: 'სრული საიტი ქართულად',
    localeHomeTitle: 'სკოლის შესახებ',
    localeHomeIntro: 'ამ ენაზე ხელმისაწვდომი გვერდები.',
    pages: 'გვერდები',
    untranslated:
        'ეს გვერდი ამ ენაზე ჯერ არ არის — გადაგიყვანთ მის მთავარ გვერდზე',
    previousPage: '← წინა გვერდი',
    nextPage: 'შემდეგი გვერდი →',
    noNews: 'ამ ეტაპზე გამოქვეყნებული ამბავი არ არის.',
    backToNews: 'ამბებში დაბრუნება',
};

type Dictionary = typeof ka;

const en: Dictionary = {
    menu: 'Menu',
    home: 'Home',
    myPortal: 'My {name}',
    admissions: 'Admissions',
    documents: 'Documents',
    journal: 'E-journal',
    madeByBefore: 'Made at ',
    madeByAfter: ', 2026',
    news: 'News',
    newsTitle: 'News',
    newsEmpty: 'News will be published soon.',
    language: 'Language',
    inOriginal: 'Full site in Georgian',
    localeHomeTitle: 'About the school',
    localeHomeIntro: 'Pages available in English.',
    pages: 'Pages',
    untranslated:
        'This page is not available in this language yet — you will be taken to its start page',
    previousPage: '← Previous page',
    nextPage: 'Next page →',
    noNews: 'No news has been published yet.',
    backToNews: 'Back to news',
};

const ru: Dictionary = {
    menu: 'Меню',
    home: 'Главная',
    myPortal: 'Мой {name}',
    admissions: 'Поступление',
    documents: 'Документы',
    journal: 'Электронный журнал',
    madeByBefore: 'Сделано в ',
    madeByAfter: ', 2026',
    news: 'Новости',
    newsTitle: 'Новости',
    newsEmpty: 'Новости скоро появятся.',
    language: 'Язык',
    inOriginal: 'Полный сайт на грузинском',
    localeHomeTitle: 'О школе',
    localeHomeIntro: 'Страницы, доступные на русском языке.',
    pages: 'Страницы',
    untranslated:
        'Эта страница пока недоступна на этом языке — вы перейдёте на главную',
    previousPage: '← Предыдущая страница',
    nextPage: 'Следующая страница →',
    noNews: 'Новостей пока нет.',
    backToNews: 'Назад к новостям',
};

const DICTIONARIES: Record<string, Dictionary> = { ka, en, ru };

const DATE_LOCALES: Record<string, string> = {
    ka: 'ka-GE',
    en: 'en-GB',
    ru: 'ru-RU',
};

export type PublicLocaleProps = {
    current: string;
    default: string;
    switcher: {
        code: string;
        label: string;
        url: string;
        current: boolean;
        sameContent: boolean;
    }[];
    alternates: Record<string, string>;
    nav: { href: string; label: string }[] | null;
    homeUrl: string;
};

export function usePublicLocale() {
    const { publicLocale } = usePage<{
        publicLocale: PublicLocaleProps | null;
    }>().props;
    const current = publicLocale?.current ?? 'ka';
    const dictionary = DICTIONARIES[current] ?? ka;

    const t = (
        key: keyof Dictionary,
        replacements: Record<string, string> = {},
    ) =>
        Object.entries(replacements).reduce(
            (text, [name, value]) => text.replace(`{${name}}`, value),
            dictionary[key] ?? ka[key],
        );

    return {
        locale: current,
        isDefault: publicLocale === null || current === publicLocale.default,
        dateLocale: DATE_LOCALES[current] ?? 'ka-GE',
        switcher: publicLocale?.switcher ?? [],
        nav: publicLocale?.nav ?? null,
        homeUrl: publicLocale?.homeUrl ?? '/',
        /** A site path in the current language: `/news` → `/en/news`. */
        path: (to: string) =>
            publicLocale === null || current === publicLocale.default
                ? to
                : `/${current}${to}`,
        t,
    };
}
