# 0003 — საჯარო გვერდების ტექსტი initial HTML-ში SSR-ის გარეშე

თარიღი: 2026-10-23 · სტატუსი: **მიღებული**

## კონტექსტი

`CLAUDE.md` მოითხოვს, რომ საჯარო კონტენტი initial HTML-ში იყოს „SSR/დოკუმენტირებული rendering გადაწყვეტით“. Inertia SSR-ს მუდმივად გაშვებული Node.js პროცესი სჭირდება. production სერვერზე (CloudPanel, site user `ais`) Node.js არ არის, root/sudo-ც არ გვაქვს და supervisor-ს ვერ ვმართავთ. ამიტომ 2026-10-23-მდე production-ის HTML-ში მხოლოდ `<title>`, `<meta description>`, canonical და hreflang იყო. გვერდის ტექსტი მხოლოდ JS-ის შემდეგ ჩნდებოდა.

## გადაწყვეტილება

- `resources/views/partials/public-content.blade.php` ინდექსირებადი საჯარო გვერდების ტექსტს Blade-ით არენდერებს. ეს ის გვერდებია, რომლებსაც canonical აქვთ: CMS გვერდები და ბლოკები, ამბები, მასწავლებლები, ბიბლიოთეკა. ბლოკი იგება იმავე Inertia props-იდან, რასაც React-ის გვერდი იღებს, ამიტომ შინაარსი ერთი და იგივეა.
- `<head>`-ის inline script (CSP nonce-ით) `<html>`-ს `js` კლასს ანიჭებს, ხოლო `.js #public-content { display: none }`. JS-იან მომხმარებელს ეს ბლოკი არ უჩანს და არც flash ხდება. React-ის გვერდი `#app`-ში ჩვეულებრივ რენდერდება. crawler-ი და JS-ის გარეშე კლიენტი ხედავს უბრალო, სემანტიკურ HTML-ს (h1/h2, ბმულები, footer-ის კონტაქტი).
- ყველა მნიშვნელობა Blade-ით escape-დება (`{{ }}`). CMS ტექსტი plain text-ია და HTML-ად არ ინტერპრეტირდება.

## შედეგები

- ვიზუალური წყარო კვლავ React-ის გვერდებია. ახალი ბლოკის ტიპის დამატებისას ის partial-შიც უნდა დაემატოს, თორემ `@default` შტო მხოლოდ heading/body/CTA-ს გამოიტანს.
- nav-ის ბმულები partial-ში `public-layout.tsx`-ის სიის ასლია. ერთის შეცვლისას მეორეც უნდა შეიცვალოს.
- თუ სერვერზე Node.js გაჩნდება, ნამდვილი Inertia SSR (`resources/js/ssr.tsx` + მუდმივი პროცესი) ამას ჩაანაცვლებს და partial წაიშლება.
- ტესტი: `tests/Feature/Content/HomePageSeoTest.php`.
