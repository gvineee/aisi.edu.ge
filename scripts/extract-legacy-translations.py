"""Extract the old site's genuine English/Russian translations of school
pages from the verified legacy package into a small reviewed JSON file.

Reads content-migration/snapshots/2026-10-25/legacy-content-import-package.zip
(never modifies it) and keeps only article text: each Elementor text-editor
widget when a page has any (instructor/testimonial template widgets are
left out), otherwise the classic `entry-content` block. Menus, footers and
comment sections (some old posts carry thousands of spam comments) are
never inside either. Writes content-migration/legacy-translations.json.
No network, no database.

Usage: python -I scripts/extract-legacy-translations.py
"""
import hashlib
import html
import json
import re
import sys
import zipfile
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlparse

ROOT = Path(__file__).resolve().parent.parent
SNAPSHOT = ROOT / 'content-migration/snapshots/2026-10-25'
OUT = ROOT / 'content-migration/legacy-translations.json'
NEWLINE = chr(10)
GEORGIAN = re.compile('[ა-ჿ]')
# Theme widgets that follow the article on some pages (instructor grid,
# demo testimonials): the text ends where one of them begins.
TEMPLATE_SECTIONS = {'Our Skilled Instructors', 'What Our Students Say', 'Наши преподаватели', 'Что говорят наши студенты'}

# Reviewed 2026-10-26: legacy URL → the Georgian source record it translates.
TRANSLATIONS = {
    '/en/2315-2/': ('en', 'wp-pages-2315'),
    '/en/about-us-3/': ('en', 'wp-pages-1252'),
    '/en/direqtoris-misalmeba/': ('en', 'wp-pages-1225'),
    '/en/events/': ('en', 'wp-pages-871'),
    '/en/ავტორიზაცია/': ('en', 'wp-pages-2337'),
    '/en/სკოლის-ისტორია/': ('en', 'wp-pages-2371'),
    '/en/ჩარიცხვის-წესი/': ('en', 'wp-pages-2313'),
    '/en/სკოლა-აისსა-და-ვარშავის-ე/': ('en', 'wp-posts-2284'),
    '/ru/about-us-3/': ('ru', 'wp-pages-1252'),
    '/ru/ავტორიზაცია/': ('ru', 'wp-pages-2337'),
    '/ru/სკოლის-ისტორია/': ('ru', 'wp-pages-2371'),
    '/ru/ჩარიცხვის-წესი/': ('ru', 'wp-pages-2313'),
}


class Blocks(HTMLParser):
    """Paragraphs, headings and list items, in order, inside the chosen
    text container class only."""

    BLOCK = {'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li'}

    def __init__(self, container: str):
        super().__init__(convert_charrefs=True)
        self.container = container
        self.blocks = []
        self.current = None
        self.depth = 0
        self.capture_depth = 0  # >0 while inside a container (counts nested divs)

    def handle_starttag(self, tag, attrs):
        if tag == 'div':
            if self.capture_depth:
                self.capture_depth += 1
            elif self.container in (dict(attrs).get('class') or '').split():
                self.capture_depth = 1
            return
        if not self.capture_depth:
            return
        if tag in self.BLOCK:
            if self.current is None:
                self.current = []
            self.depth += 1
        elif tag == 'br' and self.current is not None:
            self.current.append(NEWLINE)

    def handle_endtag(self, tag):
        if tag == 'div' and self.capture_depth:
            self.capture_depth -= 1
            return
        if tag in self.BLOCK and self.current is not None:
            self.depth -= 1
            if self.depth == 0:
                lines = ''.join(self.current).split(NEWLINE)
                text = NEWLINE.join(' '.join(line.split()) for line in lines).strip()
                if text:
                    self.blocks.append(text)
                self.current = None

    def handle_data(self, data):
        if self.current is not None:
            self.current.append(data)


def main() -> int:
    package = zipfile.ZipFile(SNAPSHOT / 'legacy-content-import-package.zip')
    records = json.loads((SNAPSHOT / 'lossless-source-records.json').read_text(encoding='utf-8'))['records']
    by_path = {unquote(urlparse(r['source_url']).path): r for r in records if r['kind'] == 'rendered_page'}

    out = []
    for path, (locale, of_key) in TRANSLATIONS.items():
        record = by_path.get(path)
        if record is None:
            print(f'missing in package: {path}', file=sys.stderr)
            return 1
        raw = package.read('source/' + record['source_snapshot'])
        page = raw.decode('utf-8', 'replace')
        match = re.search(r'<title>(.*?)</title>', page, re.S)
        title = re.split(r'\s+[–—-]\s+', html.unescape(match.group(1)).strip())[0] if match else ''
        parser = Blocks('elementor-text-editor' if 'elementor-text-editor' in page else 'entry-content')
        parser.feed(page)
        paragraphs = parser.blocks
        for index, block in enumerate(paragraphs):
            if block in TEMPLATE_SECTIONS:
                paragraphs = paragraphs[:index]
                break
        out.append({
            'translation_of': of_key,
            'locale': locale,
            'source_url': record['source_url'],
            'source_snapshot': record['source_snapshot'],
            'snapshot_sha256': hashlib.sha256(raw).hexdigest(),
            'title': title,
            # The old site left some titles untranslated or set to the site name.
            'title_needs_review': title in ('', 'School aisi', 'aisi') or GEORGIAN.search(title) is not None,
            'paragraphs': paragraphs,
        })

    OUT.write_text(json.dumps({'schema_version': 1, 'automatic_publish': False, 'records': out}, ensure_ascii=False, indent=1), encoding='utf-8')
    sys.stdout.reconfigure(encoding='utf-8')
    for r in out:
        print(r['locale'], r['translation_of'], len(r['paragraphs']), sum(map(len, r['paragraphs'])), r['title_needs_review'], r['title'][:50])
    return 0


if __name__ == '__main__':
    sys.exit(main())
