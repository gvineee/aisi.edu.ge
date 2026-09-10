"""Read-only public website export. Never writes to the source site or application DB."""
import concurrent.futures, hashlib, html, json, re, time, urllib.parse, urllib.request, urllib.error, xml.etree.ElementTree as ET
from pathlib import Path
from html.parser import HTMLParser
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'content-migration'
for part in ['raw/api','raw/pages','raw/sitemaps','assets','clean']:(OUT/part).mkdir(parents=True,exist_ok=True)
BASE='https://aisi.edu.ge/'
failures=[]
def dump(path,obj):path.write_text(json.dumps(obj,ensure_ascii=False,indent=2),encoding='utf-8')
def fetch(url):
 url=urllib.parse.quote(url,safe=':/?&=%+#@')
 for attempt in range(2):
  try:
   with urllib.request.urlopen(urllib.request.Request(url,headers={'User-Agent':'Aisi-Redesign-Public-Content-Archive/1.0'}),timeout=15) as r:return r.read(),dict(r.headers)
  except Exception as e:
   if attempt or isinstance(e,urllib.error.HTTPError) and e.code in [400,401,403,404,410]:raise e
   time.sleep(1)
def normalize(url):
 p=urllib.parse.urlsplit(html.unescape(url));path=urllib.parse.unquote(p.path)
 if p.netloc.lower().removeprefix('www.')!='aisi.edu.ge':return None
 if p.query or any(x in path for x in ['/wp-json','/wp-admin','/wp-login','/feed','/wp-content/','/wp-includes/']):return None
 return BASE.rstrip('/')+(path or '/')
class Extract(HTMLParser):
 def __init__(self):super().__init__();self.parts=[];self.links=[];self.images=[];self.skip=0
 def handle_starttag(self,t,attrs):
  a=dict(attrs)
  if t in ('script','style','noscript'):self.skip+=1
  if t in ('p','div','li','h1','h2','h3','h4','br','tr'):self.parts.append('\n')
  if t=='a' and a.get('href'):self.links.append(a['href'])
  if t=='img':
   for key in ['src','data-src']:
    if a.get(key):self.images.append(a[key])
   if a.get('srcset'):self.images.extend(x.strip().split(' ')[0] for x in a['srcset'].split(','))
 def handle_endtag(self,t):
  if t in ('script','style','noscript'):self.skip=max(0,self.skip-1)
  if t in ('p','div','li','h1','h2','h3','h4','tr'):self.parts.append('\n')
 def handle_data(self,d):
  if not self.skip:self.parts.append(d)
 def text(self):
  s=html.unescape(''.join(self.parts))
  # Preserve text from builder heading attributes before removing layout shortcodes.
  s=re.sub(r'\[vc_custom_heading\s+[^\]]*?text=["“”]([^"“”]*?)["“”][^\]]*\]',r'\n\1\n',s)
  s=re.sub(r'\[/?(?:vc_[^\]]*|rt_[^\]]*|contact-form-7[^\]]*|layerslider[^\]]*)\]','',s)
  return '\n\n'.join(x.strip() for x in re.split(r'\n+',s) if x.strip())
api={};assets=set();urls=set();records=[]
for kind in ['pages','posts','media']:
 rows=[];page=1
 while True:
  cached=OUT/f'raw/api/{kind}-{page}.json'
  if cached.exists():data=cached.read_bytes();headers={'X-WP-TotalPages':str(len(list((OUT/'raw/api').glob(kind+'-*.json'))))}
  else:data,headers=fetch(f'{BASE}wp-json/wp/v2/{kind}?per_page=100&page={page}')
  chunk=json.loads(data);dump(OUT/f'raw/api/{kind}-{page}.json',chunk);rows+=chunk
  total=int(next((v for k,v in headers.items() if k.lower()=='x-wp-totalpages'),'1'))
  if page>=total:break
  page+=1
 api[kind]=rows;print(f'{kind}: {len(rows)}',flush=True)
 for row in rows:
  if kind=='media':
   if row.get('source_url'):assets.add(row['source_url'])
   continue
  u=normalize(row['link']);urls.add(u)
  e=Extract();e.feed(row.get('content',{}).get('rendered',''))
  records.append({'source_id':row['id'],'type':kind,'source_url':row['link'],'slug':urllib.parse.unquote(row['slug']),'title':html.unescape(row['title']['rendered']),'published_at':row['date'],'modified_at':row['modified'],'featured_media':row.get('featured_media'),'clean_text':e.text(),'links':e.links,'source_images':e.images,'publication_status':'needs_review'})
def sitemap(url):
 cache=OUT/'raw/sitemaps'/Path(urllib.parse.urlsplit(url).path).name
 if cache.exists():data=cache.read_bytes()
 else:data,_=fetch(url);cache.write_bytes(data)
 tree=ET.fromstring(data);locs=[x.text for x in tree.iter() if x.tag.endswith('}loc')]
 if tree.tag.endswith('sitemapindex'):
  for loc in locs:sitemap(loc)
 else:
  for loc in locs:
   u=normalize(loc)
   if u:urls.add(u)
sitemap(BASE+'wp-sitemap.xml');urls.add(BASE)
dump(OUT/'sitemap-inventory.json',sorted(urls));print(f'Sitemap/content pages: {len(urls)}',flush=True)
pages={};external=set()
def getpage(u):
 name=hashlib.sha256(u.encode()).hexdigest()[:18]+'.html';path=OUT/'raw/pages'/name
 try:
  if path.exists():data=path.read_bytes()
  else:data,_=fetch(u);path.write_bytes(data)
  source=data.decode('utf-8',errors='replace');e=Extract();e.feed(source)
  # Public rendered page body retained separately; exact raw HTML is authoritative archive.
  title=re.search(r'<title[^>]*>(.*?)</title>',source,re.S|re.I)
  return u,{'source_url':u,'raw_file':'raw/pages/'+name,'title':html.unescape(title.group(1)) if title else '', 'rendered_text':e.text(),'links':[urllib.parse.urljoin(u,x) for x in e.links],'images':[urllib.parse.urljoin(u,x) for x in e.images]},None
 except Exception as exc:return u,None,str(exc)
pending=set(urls)
while pending:
 if len(pages)+len(pending)>1200:
  failures.append({'stage':'crawl','error':'1200-page safety limit reached','pending':sorted(pending)});break
 batch=sorted(pending);pending=set()
 with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
  for future in concurrent.futures.as_completed([pool.submit(getpage,u) for u in batch]):
   u,row,error=future.result()
   if error:failures.append({'url':u,'stage':'page','error':error});pages[u]={'error':error};continue
   pages[u]=row
   if len(pages)%50==0:
    dump(OUT/'failures.json',failures);dump(OUT/'rendered-pages.json',list(pages.values()));print(f'Processed {len(pages)} pages',flush=True)
   for link in row['links']+row['images']:
    p=urllib.parse.urlsplit(link)
    if p.netloc.lower().removeprefix('www.')=='aisi.edu.ge':
     if '/wp-content/uploads/' in p.path:assets.add(link.split('#')[0].split('?')[0])
     else:
      n=normalize(link)
      if n and n not in pages and not re.search(r'\.(?:xml|jpg|jpeg|png|gif|css|js|ico|svg|txt)$',n,re.I):pending.add(n)
    elif p.scheme in ('http','https'):external.add(link)
 print(f'Pages archived: {len(pages)}; additional discovered: {len(pending)}',flush=True)
dump(OUT/'rendered-pages.json',list(pages.values()));dump(OUT/'content-records.json',records);dump(OUT/'external-links.json',sorted(external))
def asset(url):
 ext=Path(urllib.parse.unquote(urllib.parse.urlsplit(url).path)).suffix.lower() or '.bin'
 name=hashlib.sha256(url.encode()).hexdigest()[:20]+ext;path=OUT/'assets'/name
 try:
  if path.exists():data=path.read_bytes()
  else:data,headers=fetch(url);path.write_bytes(data)
  return {'source_url':url,'local_path':'assets/'+name,'bytes':len(data),'sha256':hashlib.sha256(data).hexdigest(),'rights_status':'school_confirmation_required','download_status':'saved'}
 except Exception as exc:
  failures.append({'url':url,'stage':'asset','error':str(exc)});return {'source_url':url,'download_status':'failed','error':str(exc)}
print(f'Downloading {len(assets)} same-site assets',flush=True)
with concurrent.futures.ThreadPoolExecutor(max_workers=6) as pool:
 asset_rows=[]
 for i,row in enumerate(pool.map(asset,sorted(assets)),1):
  asset_rows.append(row)
  if i%25==0:print(f'Assets {i}/{len(assets)}',flush=True)
dump(OUT/'asset-manifest.json',asset_rows);dump(OUT/'failures.json',failures)
report={'retrieved_at':time.strftime('%Y-%m-%dT%H:%M:%SZ',time.gmtime()),'api_counts':{k:len(v) for k,v in api.items()},'pages_archived':sum('error' not in p for p in pages.values()),'pages_attempted':len(pages),'assets_saved':sum(a['download_status']=='saved' for a in asset_rows),'asset_bytes':sum(a.get('bytes',0) for a in asset_rows),'external_links':len(external),'failures':len(failures),'scope':'Public REST pages/posts/media + sitemap and reachable same-site no-query links. No authentication, external books or database export.'}
dump(OUT/'collection-report.json',report);print(json.dumps(report,ensure_ascii=False),flush=True)
