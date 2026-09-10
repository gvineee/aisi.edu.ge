import {ArrowUpRight} from 'lucide-react';
import content from './school/content.json';
import './school/school.css';
export default function SchoolNews(){return <div className="legacy-news">{content.filter(r=>r.type==='posts').slice(0,3).map(r=><a key={r.key} href={'/school?item='+r.key}>{r.image&&<img src={r.image} alt=""/>}<div className="news-copy"><small>{r.published_at?new Date(r.published_at).toLocaleDateString('ka-GE',{year:'numeric',month:'long',day:'numeric'}):'სკოლის ამბავი'}</small><h3>{r.title}</h3><p>{r.excerpt.length>180?r.excerpt.slice(0,177)+'…':r.excerpt}</p><span className="text-link">წაიკითხე ამბავი <ArrowUpRight size={16}/></span></div></a>)}</div>}
