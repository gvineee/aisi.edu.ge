"""Create reviewable CMS payloads from immutable public snapshots. No DB writes."""
import json,re,html,hashlib,shutil,csv
from pathlib import Path
from html.parser import HTMLParser
ROOT=Path(__file__).resolve().parents[1];OUT=ROOT/'content-migration'
def load(p):return json.loads(p.read_text(encoding='utf-8'))
def dump(p,v):p.parent.mkdir(parents=True,exist_ok=True);p.write_text(json.dumps(v,ensure_ascii=False,indent=2),encoding='utf-8')
class Clean(HTMLParser):
 def __init__(self):super().__init__();self.parts=[];self.links=[];self.a=None;self.skip=0
 def handle_starttag(self,t,attrs):
  a=dict(attrs)
  if t in ['script','style']:self.skip+=1
  if t in ['p','div','li','h1','h2','h3','h4','br','tr']:self.parts.append('\n')
  if t=='a':self.a={'url':a.get('href',''),'label':''}
 def handle_endtag(self,t):
  if t in ['script','style']:self.skip=max(0,self.skip-1)
  if t in ['p','div','li','h1','h2','h3','h4','tr']:self.parts.append('\n')
  if t=='a' and self.a:self.links.append(self.a);self.a=None
 def handle_data(self,d):
  if not self.skip:self.parts.append(d)
  if self.a:self.a['label']+=d
 def text(self):
  t=html.unescape(''.join(self.parts));t=re.sub(r'\[/?[^\]]+\]','',t)
  return '\n\n'.join(re.sub(r'[ \t]+',' ',x).strip() for x in t.splitlines() if x.strip())
overrides={
2371:('სკოლის ისტორია','აისი — განათლების გზა, რომელიც 2000 წელს დაიწყო.','სკოლა „აისი“ 2000 წელს თბილისში დაარსდა. მისი დამფუძნებელია პედაგოგიკის დოქტორი იანა ტორჩინავა.\n\nორი მოსწავლით დაწყებული სკოლის ისტორიაში განათლების ხელმისაწვდომობასა და თითოეული ბავშვის შესაძლებლობების განვითარებას მნიშვნელოვანი ადგილი უჭირავს.\n\nაისის ისტორია მოსწავლეების, მასწავლებლებისა და ოჯახების თანამშრომლობას აერთიანებს. სკოლის მიზანია ცოდნა, ცნობისმოყვარეობა და დამოუკიდებელი არჩევანის უნარი ყოველდღიური სწავლის ნაწილად აქციოს.','/about/history'),
1252:('მისია და ღირებულებები','ცოდნა, თანამშრომლობა და საკუთარი გზის პოვნა.','ჩვენი მისიაა მოსწავლის განათლებისა და პიროვნული განვითარების ხელშეწყობა — თანასწორობის, ტოლერანტობისა და ადამიანის ღირსების პატივისცემით.\n\nმოსწავლეების, ოჯახებისა და მასწავლებლების თანამშრომლობით ვქმნით გარემოს, სადაც თითოეულ ბავშვს საკუთარი ინტერესებისა და შესაძლებლობების განვითარება შეუძლია.\n\nდაწყებითი საფეხური: საბაზისო უნარები, წიგნიერება, პასუხისმგებლობა და სწავლის მიმართ დადებითი განწყობა.\n\nსაბაზო საფეხური: ლოგიკური აზროვნება, ინტერესების აღმოჩენა, დამოუკიდებლობა და თავდაჯერებულობა.\n\nსაშუალო საფეხური: ინტერესების გაღრმავება, კრიტიკული აზროვნება და შემდგომი განათლებისა თუ პროფესიული არჩევანისთვის მომზადება.','/about/mission'),
2347:('რატომ აისი','სკოლის არჩევა იწყება იმით, რაც თქვენს შვილს სჭირდება.','აკადემიური განვითარება\nჩვენთვის მნიშვნელოვანია ძლიერი სასწავლო პროცესი, ცოდნის გამოყენება და მოსწავლის წინსვლა.\n\nმასწავლებლები\nაისის პედაგოგები ერთმანეთს უზიარებენ გამოცდილებას და პროფესიულ განვითარებაზე მუშაობენ.\n\nთანამშრომლობა და შემოქმედებითობა\nსკოლის გარემო ხელს უწყობს გუნდურ მუშაობას, იდეების გაზიარებასა და საკუთარი ინტერესების აღმოჩენას.\n\nკლასგარეშე ცხოვრება\nსწავლა კლასის მიღმაც გრძელდება — აქტივობებითა და პროექტებით. კონკრეტული მიმდინარე შეთავაზებები იხილეთ სკოლის კალენდარში.\n\nინდივიდუალური მიდგომა\nბავშვების ინტერესები და შესაძლებლობები განსხვავდება. ჩვენთვის მნიშვნელოვანია თითოეული მოსწავლის საჭიროების გაგება და ოჯახთან თანამშრომლობა.\n\nგაიცანით სკოლა\nვიზიტის დროს განვიხილოთ თქვენი შვილის საჭიროებები, სასწავლო გარემო და მხარდაჭერის ხელმისაწვდომი შესაძლებლობები.','/about/why-aisi'),
1225:('დირექტორის მისალმება','კეთილი იყოს თქვენი მობრძანება აისში.','ძვირფასო მშობლებო და მოსწავლეებო,\n\nმადლობას გიხდით ჩვენი სკოლისადმი ინტერესისთვის. აისში გვჯერა, რომ სკოლა ცოდნის მიღებასთან ერთად ბავშვის პიროვნული განვითარების სივრცეა.\n\nთითოეულ მოსწავლეს განსხვავებული ინტერესები, ნიჭი და შესაძლებლობები აქვს. ჩვენი ამოცანაა მათი აღმოჩენა, პატივისცემა და განვითარებაში დახმარება.\n\nგვინდა, სკოლასა და ოჯახს შორის თანამშრომლობა ბავშვის ყოველდღიურობის ბუნებრივი ნაწილი იყოს. ერთად შევქმნათ გარემო, სადაც მოსწავლეებს შეკითხვების დასმისა და ახალი გამოწვევების მიღების გამბედაობა ექნებათ.\n\nგელოდებით აისში — აქ იწყება შენი ხვალ.','/about/welcome'),
1111:('კონტაქტი','მოდი, გავიცნოთ ერთმანეთი.','მისამართი: თბილისი, დიდი დიღომი, დავარის ქუჩა 64.\n\nტელეფონი: +995 599 15 98 00\n\nელფოსტა: info@aisi.edu.ge\n\nვიზიტის დრო და მიღების მიმდინარე პირობები წინასწარ შეათანხმეთ სკოლასთან.','/contact'),
}
allrows=[]
for kind in ['pages','posts']:
 for file in (OUT/'raw/api').glob(kind+'-*.json'):
  for r in load(file):
   e=Clean();e.feed(r.get('content',{}).get('rendered',''));text=e.text();title=html.unescape(r['title']['rendered']).strip() or 'პარტნიორობა ვარშავის უნივერსიტეტთან'
   target='/news/'+str(r['id']) if kind=='posts' else '/pages/'+str(r['id'])
   row={'key':f'wp-{kind}-{r["id"]}','source_id':r['id'],'type':kind,'source_url':r['link'],'source_title':title,'title':title,'excerpt':text[:220],'original_clean_text':text,'body':text,'links':e.links,'published_at':r['date'],'modified_at':r['modified'],'featured_media':r.get('featured_media',0),'target_path':target,'editorial_status':'cleaned_needs_review','cms_status':'draft','review_notes':[]}
   if r['id'] in overrides:
    title,excerpt,body,target=overrides[r['id']];row.update(title=title,excerpt=excerpt,body=body,target_path=target,editorial_status='rewritten_needs_approval')
    row['review_notes'].append('შემოკლებული სარედაქციო ვერსია. სრული ორიგინალი შენარჩუნებულია; სკოლის დამტკიცება აუცილებელია.')
   if r['id'] in [1241,2159]:row.update(target_path='/about/history',editorial_status='merge_review');row['review_notes'].append('ისტორიის დუბლიკატი; შეადარეთ ძირითად source_id 2371-ს.')
   if r['id']==871:row.update(target_path='/about/why-aisi',editorial_status='merge_review')
   if r['id']==54:row.update(target_path='/',editorial_status='layout_source_only');row['review_notes'].append('ძველი მთავარი გვერდის სტატისტიკა და შერეული ბლოკები მიმდინარე ფაქტებად არ გადაიტანოთ.')
   if r['id'] in [2313,2334,2337,2315,1205]:row['review_notes'].append('სკოლამ გადაამოწმოს წესები, პასუხისმგებელი პირი, დოკუმენტის ვერსია, სტატუსი ან ფინანსური პირობა. სამართლებრივი რედაქტირება არ შესრულებულა.')
   if r['id']==2337:row['review_notes'].append('2022 წლის ექვსწლიანი ავტორიზაციის ცნობა არ უდრის დღეს დამოუკიდებლად შემოწმებულ სტატუსს; 2021 წლის საერთაშორისო აკრედიტაციის დაწყება არ არის მიღებული აკრედიტაცია.')
   if r['id']==949:row.update(target_path='/library');row['review_notes'].append('გარე წიგნები შენახულია ბმულებად; გადანაწილების უფლება არ შემოწმებულა.')
   if r['id']==1263:row.update(target_path='/teachers');row['review_notes'].append('პედაგოგთა მიმდინარე შემადგენლობა დასადასტურებელია.')
   allrows.append(row)
# Include sitemap custom content not exposed through wp/v2 (instructors, rules, events).
known={r['source_url'].rstrip('/') for r in allrows}
for url in load(OUT/'sitemap-inventory.json'):
 if url.rstrip('/') in known or not any(x in url for x in ['/instructor/','/courses/','/course/','/research/','/event/','/gallery/','/testimonial/']):continue
 path=OUT/'raw/pages'/(hashlib.sha256(url.encode()).hexdigest()[:18]+'.html')
 if not path.exists():continue
 source=path.read_text(encoding='utf-8');main=re.search(r'<main\b[^>]*>(.*?)</main>',source,re.S|re.I)
 if not main:continue
 e=Clean();e.feed(main.group(1));text=e.text()
 match=re.search(r'<h[1-3][^>]*>(.*?)</h[1-3]>',main.group(1),re.S|re.I)
 title=html.unescape(re.sub('<[^>]+>','',match.group(1))).strip() if match else 'საარქივო გვერდი'
 key='legacy-'+hashlib.sha256(url.encode()).hexdigest()[:12]
 kind='teachers' if '/instructor/' in url else 'documents'
 georgian=len(re.findall('[ა-ჰ]',text));template=georgian<20
 row={'key':key,'source_id':None,'type':kind,'source_url':url,'source_title':title,'title':title,'excerpt':text[:220],'original_clean_text':text,'body':text,'links':e.links,'published_at':None,'modified_at':None,'featured_media':0,'target_path':('/teachers/' if kind=='teachers' else '/documents/')+key,'editorial_status':'template_review' if template else 'cleaned_needs_review','cms_status':'draft','review_notes':['პირდაპირი საჯარო გვერდის main-დან ამოღებული შინაარსი. შემადგენლობა/ვერსია დასადასტურებელია; გვერდის შაბლონის ნარჩენები გადასამოწმებელია.']}
 allrows.append(row)
dump(OUT/'cms-import.json',{'schema_version':1,'tenant_required':True,'default_status':'draft','records':allrows})
for row in allrows:
 text=f'# {row["title"]}\n\nწყარო: {row["source_url"]}\n\nსტატუსი: {row["editorial_status"]}\n\n## ახალი ვერსია\n\n{row["body"]}\n\n## წყაროს სრული გასუფთავებული ტექსტი\n\n{row["original_clean_text"]}\n\n## გადასამოწმებელი\n\n'+('\n'.join('- '+x for x in row['review_notes']) or '- სკოლის სარედაქციო დამტკიცება.')+'\n'
 (OUT/'clean'/f'{row["key"]}.md').write_text(text,encoding='utf-8')
with (OUT/'redirect-map.csv').open('w',encoding='utf-8-sig',newline='') as f:
 writer=csv.writer(f);writer.writerow(['source_url','proposed_target','status'])
 for r in allrows:writer.writerow([r['source_url'],r['target_path'],'review_before_301'])
media={r['id']:r for p in (OUT/'raw/api').glob('media-*.json') for r in load(p)}
manifest={r['source_url']:r for r in load(OUT/'asset-manifest.json')} if (OUT/'asset-manifest.json').exists() else {}
preview=[]
for row in allrows:
 if row['editorial_status'] in ['merge_review','layout_source_only','template_review']:continue
 image=None;m=media.get(row['featured_media']);asset=manifest.get(m.get('source_url')) if m else None
 if asset and asset.get('local_path') and row['type']=='posts':
  src=OUT/asset['local_path']
  if src.suffix.lower() in ['.jpg','.jpeg','.png','.webp']:
   dest=ROOT/'design/public/migrated'/src.name;dest.parent.mkdir(exist_ok=True);shutil.copy2(src,dest);image='/migrated/'+src.name
 preview.append({k:row[k] for k in ['key','source_id','type','title','excerpt','body','source_url','published_at','editorial_status','review_notes','links']}|{'image':image})
dump(ROOT/'design/app/school/content.json',preview)
print('Prepared',len(allrows),'CMS records,',len(preview),'preview records; all draft.')
