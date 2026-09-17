// The four deck screenshots from Degrees production, as a STUDENT.
// Slide 5 is taken on the course page (where a learner first meets SOLA);
// 8, 9 and 10 on a unit page, where all starters are offered and meta.pageurl
// is populated so a [SOURCE:page] citation can actually link.
const fs=require('fs'),os=require('os'),path=require('path'),puppeteer=require('puppeteer');
const BASE='https://degrees.saylor.org';
const COURSE=20, CMID=20059;  // "Introduction to Strategic Management"
const OUT=path.join(os.homedir(),'Desktop','sola-deck-screenshots');
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
function pc(){const c='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';return fs.existsSync(c)?c:puppeteer.executablePath();}

async function openDrawer(p){
  await p.waitForSelector('.local-ai-course-assistant__toggle',{timeout:30000});
  const open=await p.evaluate(()=>{const d=document.querySelector('#local-ai-course-assistant-drawer');
    return !!d && d.classList.contains('local-ai-course-assistant__drawer--open');});
  if(!open){await p.click('.local-ai-course-assistant__toggle');}
  await sleep(3000);
}
// Poll with a fresh short evaluate each time; one long-lived evaluate is what
// hung the previous run against the protocol timeout.
async function settle(p,maxms){
  const t0=Date.now();
  await sleep(3000);
  while(Date.now()-t0<maxms){
    let streaming=true;
    try{ streaming=await p.evaluate(()=>[...document.querySelectorAll('button')]
      .some(b=>/^\s*(Stop|Cancel)\s*$/i.test(b.textContent||''))); }
    catch(e){ return 'evaluate failed: '+e.message.slice(0,40); }
    if(!streaming){return 'complete';}
    await sleep(2000);
  }
  return 'timeout';
}
async function shot(p,n,name){
  const f=path.join(OUT,`${n}-${name}.png`);
  const d=await p.$('#local-ai-course-assistant-drawer');
  if(d){await d.screenshot({path:f});}else{await p.screenshot({path:f});}
  console.log('  saved',path.basename(f));
}
(async()=>{
 fs.mkdirSync(OUT,{recursive:true});
 const b=await puppeteer.launch({executablePath:pc(),headless:true,protocolTimeout:600000,
   args:['--no-sandbox'],defaultViewport:{width:1500,height:1000}});
 const p=await b.newPage();
 p.on('dialog',async d=>{await d.dismiss();});
 await p.setCookie({name:'MoodleSession',value:process.env.SOLA_COOKIE,domain:'degrees.saylor.org',path:'/',httpOnly:true,secure:true});
 let meta=null;
 p.on('response',async r=>{ if(/ai_course_assistant\/sse\.php/.test(r.url())){
   try{const t=await r.text();const m=t.match(/data: (\{"type":"meta".*?\})\n/);if(m){meta=JSON.parse(m[1]);}}catch(e){} }});

 // ---- Slide 5: the drawer as a learner first meets it, on the course page ----
 await p.goto(`${BASE}/course/view.php?id=${COURSE}`,{waitUntil:'domcontentloaded'});
 await sleep(3500);
 await openDrawer(p);
 for(const sel of ['.local-ai-course-assistant__btn-clear','.local-ai-course-assistant__btn-reset']){
   try{const el=await p.$(sel); if(el){await el.click();await sleep(1500);}}catch(e){}
 }
 await shot(p,1,'drawer-open');

 // ---- Slides 8-10 from a unit page ----
 await p.goto(`${BASE}/mod/page/view.php?id=${CMID}`,{waitUntil:'domcontentloaded'});
 await sleep(3500);
 await openDrawer(p);
 console.log('  on:',await p.evaluate(()=>document.title.slice(0,46)));

 await p.waitForSelector('.local-ai-course-assistant__input',{timeout:20000});
 await p.click('.local-ai-course-assistant__input');
 await p.type('.local-ai-course-assistant__input','What is strategic management, and why does it matter?',{delay:8});
 await p.click('.local-ai-course-assistant__btn-send');
 console.log('  answer:',await settle(p,180000));
 const diag=await p.evaluate(()=>{const s=document.querySelector('.aica-source-pill');
   return s?{tag:s.tagName,href:s.getAttribute('href'),text:s.textContent.trim().slice(0,50)}:null;});
 console.log('  SOURCE PILL:',JSON.stringify(diag));
 console.log('  meta.pageurl:',meta?JSON.stringify(meta.pageurl):'(none)');
 try{await p.evaluate(()=>{const m=document.querySelector('.local-ai-course-assistant__messages');if(m){m.scrollTop=m.scrollHeight;}});}catch(e){}
 await sleep(900);
 await shot(p,2,'question-and-answer');

 try{await p.evaluate(()=>{const s=document.querySelector('[data-starter="quiz"]');if(s){s.click();}});}catch(e){}
 await sleep(2500);
 const go=await p.$('.aica-quiz-setup__start'); if(go){await go.click();}
 await p.waitForSelector('.aica-quiz__choice',{timeout:180000}).catch(()=>null);
 await sleep(1500);
 const ch=await p.$$('.aica-quiz__choice');
 if(ch.length){await ch[0].click();await sleep(3000);console.log(`  quiz answered (${ch.length} choices)`);}
 else{console.log('  quiz: NO CHOICES');}
 try{await p.evaluate(()=>{const c=document.querySelector('.aica-quiz');if(c){c.scrollIntoView({block:'start'});}});}catch(e){}
 await sleep(800);
 await shot(p,3,'quiz-with-explanation');

 for(const sel of ['.aica-quiz__exit','.local-ai-course-assistant__btn-clear','.local-ai-course-assistant__btn-reset']){
   try{const el=await p.$(sel); if(el){await el.click();await sleep(1400);}}catch(e){}
 }
 try{await p.evaluate(()=>{const s=document.querySelector('[data-starter="study-plan"]');if(s){s.click();}});}catch(e){}
 await sleep(2500);
 const go2=await p.$('.aica-quiz-setup__start'); if(go2){await go2.click();}
 console.log('  study plan:',await settle(p,180000));
 try{await p.evaluate(()=>{const m=[...document.querySelectorAll('.local-ai-course-assistant__messages > *')];
   const last=m.reverse().find(e=>(e.textContent||'').trim().length>200); if(last){last.scrollIntoView({block:'start'});}});}catch(e){}
 await sleep(900);
 await shot(p,4,'study-plan');
 await b.close();console.log('DONE ->',OUT);
})().catch(e=>{console.error('ERR',e.message);process.exit(1)});
