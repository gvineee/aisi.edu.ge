{{--
    Server-rendered text of a public page for crawlers and JS-less clients.
    Production has no Node.js, so Inertia SSR can't run there (see
    docs/decisions/0003-public-rendering-without-ssr.md). This markup is built
    from the same props the React page receives, so it carries the same
    content; it is hidden as soon as the inline head script marks the document
    as `.js`, and React renders the real page into #app.
--}}
@php
    $props = $page['props'];
    $component = $page['component'];
    $brandName = data_get($props, 'brand.name', data_get($props, 'name'));
    $formatDate = fn (?string $iso) => $iso
        ? \Illuminate\Support\Carbon::parse($iso)->timezone('Asia/Tbilisi')->locale('ka')->isoFormat('LL')
        : null;
    $navItems = [
        '/about' => 'სკოლის შესახებ',
        '/learning' => 'სწავლა',
        '/school-life' => 'სასკოლო ცხოვრება',
        '/news' => 'ამბები',
        '/library' => 'ბიბლიოთეკა',
        '/contact' => 'კონტაქტი',
    ];
@endphp
<div id="public-content">
    <header>
        <a href="/">{{ $brandName }}</a>
        <nav>
            <ul>
                @foreach ($navItems as $href => $label)
                    <li><a href="{{ $href }}">{{ $label }}</a></li>
                @endforeach
            </ul>
        </nav>
    </header>

    <main>
        @switch($component)
            @case('public/page')
                @if ($crumb = data_get($props, 'breadcrumb'))
                    <nav aria-label="breadcrumb"><a href="{{ $crumb['href'] }}">{{ $crumb['name'] }}</a></nav>
                @endif
                @php
                    $blocks = data_get($props, 'page.blocks', []);
                    $hasHero = collect($blocks)->contains(fn ($block) => ($block['type'] ?? null) === 'hero');
                @endphp
                @unless ($hasHero)
                    <h1>{{ data_get($props, 'page.title') }}</h1>
                @endunless
                @foreach ($blocks as $block)
                    <section>
                        @switch($block['type'] ?? null)
                            @case('hero')
                                @isset($block['eyebrow'])<p>{{ $block['eyebrow'] }}</p>@endisset
                                <h1>{{ collect([$block['heading'] ?? null, $block['headingLine2'] ?? null, $block['headingEmphasis'] ?? null])->filter()->implode(' ') }}</h1>
                                @isset($block['body'])<p>{{ $block['body'] }}</p>@endisset
                                @isset($block['note'])<p>{{ $block['note'] }}</p>@endisset
                                @break
                            @case('values')
                                <ul>
                                    @foreach ($block['items'] ?? [] as $item)
                                        <li>{{ $item['label'] ?? '' }}</li>
                                    @endforeach
                                </ul>
                                @break
                            @case('programs')
                                <h2>{{ $block['heading'] ?? '' }}</h2>
                                @foreach ($block['items'] ?? [] as $item)
                                    <h3>{{ $item['title'] ?? '' }}</h3>
                                    <p>{{ $item['grade'] ?? '' }}</p>
                                    <p>{{ $item['body'] ?? '' }}</p>
                                @endforeach
                                @break
                            @case('text')
                                <h2>{{ $block['heading'] ?? '' }}</h2>
                                @foreach ((array) ($block['body'] ?? []) as $paragraph)
                                    <p>{{ $paragraph }}</p>
                                @endforeach
                                @break
                            @case('life')
                                <h2>{{ $block['heading'] ?? '' }}</h2>
                                <ul>
                                    @foreach (data_get($props, 'latestPosts', []) as $post)
                                        <li><a href="/news/{{ $post['slug'] }}">{{ $post['title'] }}</a></li>
                                    @endforeach
                                </ul>
                                @break
                            @case('teachers')
                                <h2>{{ $block['heading'] ?? '' }}</h2>
                                <ul>
                                    @foreach (data_get($props, 'featuredTeachers', []) as $teacher)
                                        <li><a href="/teachers/{{ $teacher['slug'] }}">{{ $teacher['name'] }}</a>@if ($teacher['subject']) — {{ $teacher['subject'] }}@endif</li>
                                    @endforeach
                                </ul>
                                @break
                            @case('documents')
                                @isset($block['heading'])<h2>{{ $block['heading'] }}</h2>@endisset
                                @foreach ($block['groups'] ?? [] as $group)
                                    <h3>{{ $group['heading'] ?? '' }}</h3>
                                    <ul>
                                        @foreach ($group['items'] ?? [] as $item)
                                            <li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a></li>
                                        @endforeach
                                    </ul>
                                @endforeach
                                @break
                            @default
                                {{-- history, portal_promo, contact_cta --}}
                                @isset($block['eyebrow'])<p>{{ $block['eyebrow'] }}</p>@endisset
                                @isset($block['heading'])<h2>{{ $block['heading'] }}</h2>@endisset
                                @isset($block['lead'])<p>{{ $block['lead'] }}</p>@endisset
                                @isset($block['body'])<p>{{ $block['body'] }}</p>@endisset
                                @if (isset($block['ctaHref'], $block['ctaLabel']))
                                    <p><a href="{{ $block['ctaHref'] }}">{{ $block['ctaLabel'] }}</a></p>
                                @endif
                        @endswitch
                    </section>
                @endforeach
                @foreach (data_get($props, 'media.images', []) as $image)
                    <figure><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}">@if ($image['caption'])<figcaption>{{ $image['caption'] }}</figcaption>@endif</figure>
                @endforeach
                @if ($files = data_get($props, 'media.attachments'))
                    <ul>
                        @foreach ($files as $file)
                            <li><a href="{{ $file['url'] }}">{{ $file['label'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                @if ($section = data_get($props, 'categorySection'))
                    <section>
                        <h2>{{ $section['name'] }}</h2>
                        <ul>
                            @foreach ($section['items'] as $item)
                                <li><a href="{{ $item['url'] }}">{{ $item['title'] }}</a>@if ($item['date']) — <time datetime="{{ $item['date'] }}">{{ $formatDate($item['date']) }}</time>@endif</li>
                            @endforeach
                        </ul>
                    </section>
                @endif
                @break

            @case('public/news-index')
                <h1>ამბები</h1>
                <ul>
                    @foreach (data_get($props, 'posts.data', []) as $post)
                        <li>
                            <a href="/news/{{ $post['slug'] }}">{{ $post['title'] }}</a>
                            @if ($post['publishedAt'])<time datetime="{{ $post['publishedAt'] }}">{{ $formatDate($post['publishedAt']) }}</time>@endif
                            @if ($post['excerpt'])<p>{{ $post['excerpt'] }}</p>@endif
                        </li>
                    @endforeach
                </ul>
                @if ($prev = data_get($props, 'posts.prev_page_url'))
                    <a href="{{ $prev }}" rel="prev">წინა</a>
                @endif
                @if ($next = data_get($props, 'posts.next_page_url'))
                    <a href="{{ $next }}" rel="next">შემდეგი</a>
                @endif
                @break

            @case('public/news-show')
                @if ($crumb = data_get($props, 'breadcrumb'))
                    <nav aria-label="breadcrumb"><a href="{{ $crumb['href'] }}">{{ $crumb['name'] }}</a></nav>
                @endif
                <article>
                    @if ($published = data_get($props, 'post.publishedAt'))
                        <time datetime="{{ $published }}">{{ $formatDate($published) }}</time>
                    @endif
                    <h1>{{ data_get($props, 'post.title') }}</h1>
                    <p style="white-space: pre-line">{{ data_get($props, 'post.body') }}</p>
                </article>
                @foreach (data_get($props, 'media.images', []) as $image)
                    <figure><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}">@if ($image['caption'])<figcaption>{{ $image['caption'] }}</figcaption>@endif</figure>
                @endforeach
                @if ($files = data_get($props, 'media.attachments'))
                    <ul>
                        @foreach ($files as $file)
                            <li><a href="{{ $file['url'] }}">{{ $file['label'] }}</a></li>
                        @endforeach
                    </ul>
                @endif
                @break

            @case('public/teachers/index')
                <h1>მასწავლებლები</h1>
                <ul>
                    @foreach (data_get($props, 'teachers', []) as $teacher)
                        <li><a href="/teachers/{{ $teacher['slug'] }}">{{ $teacher['name'] }}</a>@if ($teacher['subject']) — {{ $teacher['subject'] }}@endif</li>
                    @endforeach
                </ul>
                @break

            @case('public/teachers/show')
                <h1>{{ data_get($props, 'teacher.name') }}</h1>
                @if ($subject = data_get($props, 'teacher.subject'))<p>{{ $subject }}</p>@endif
                @if ($bio = data_get($props, 'teacher.bio'))<p style="white-space: pre-line">{{ $bio }}</p>@endif
                @break

            @case('public/library-index')
                <h1>ბიბლიოთეკა</h1>
                <ul>
                    @foreach (data_get($props, 'resources.data', []) as $resource)
                        <li>{{ $resource['title'] }}@if ($resource['author']) — {{ $resource['author'] }}@endif</li>
                    @endforeach
                </ul>
                @break
        @endswitch
    </main>

    <footer>
        <p>{{ $brandName }}</p>
        @if ($address = data_get($props, 'brand.contact.address'))<p>{{ $address }}</p>@endif
        @if ($phone = data_get($props, 'brand.contact.phone'))<p><a href="tel:{{ preg_replace('/\s+/', '', $phone) }}">{{ $phone }}</a></p>@endif
        @if ($email = data_get($props, 'brand.contact.email'))<p><a href="mailto:{{ $email }}">{{ $email }}</a></p>@endif
    </footer>
</div>
