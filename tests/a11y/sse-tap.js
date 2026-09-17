// Record the SSE frames the BROWSER actually receives for one chat turn, and
// report which protocol markers the model emitted.
//
// This exists because the obvious approach is wrong in a way that looks right.
// Calling response.text() on a live EventSource body returns a partial copy, so
// an earlier capture reported neither marker while chips demonstrably rendered
// on screen -- and the chips turned out to come from a client-side fallback, not
// from the stream at all. This wraps EventSource and fetch before any page
// script runs, so what it prints is what the client saw.
//
// It answered the question that shaped the v7.5.0 source-attribution fix: on a
// Degrees course page with three chunks retrieved, the reply carried NEITHER
// [SOURCE:] nor a single [[c:N]]. Note when reading the output that the server
// strips SOLA_NEXT from the stream, so its absence is expected and proves
// nothing; [SOURCE:] and [[c:N]] are not stripped, so theirs does.
//
// Usage:
//   SOLA_COOKIE=<MoodleSession value> TAP_OUT=/tmp/sse-tap.txt node sse-tap.js
//
// The cookie is a real session, so run this against a site you are entitled to
// use and do not commit the value.

const fs=require('fs'),puppeteer=require('puppeteer');
function pc(){const c='/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';return fs.existsSync(c)?c:puppeteer.executablePath();}
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const b=await puppeteer.launch({executablePath:pc(),headless:true,protocolTimeout:600000,args:['--no-sandbox'],defaultViewport:{width:1500,height:1000}});
 const p=await b.newPage();
 await p.evaluateOnNewDocument(()=>{
   window.__frames=[];
   const Native=window.EventSource;
   if(Native){
     window.EventSource=function(url,cfg){
       const es=new Native(url,cfg);
       es.addEventListener('message',e=>{try{window.__frames.push(String(e.data));}catch(_){}} );
       return es;
     };
     window.EventSource.prototype=Native.prototype;
   }
   // chat.js may stream via fetch+ReadableStream instead; tap that too.
   const of=window.fetch;
   window.fetch=async function(...a){
     const res=await of.apply(this,a);
     try{
       const u=(a[0]&&a[0].url)||String(a[0]||'');
       if(/sse\.php/.test(u)&&res.body){
         const [x,y]=res.body.tee();
         const rd=y.getReader(); const dec=new TextDecoder();
         (async()=>{for(;;){const {done,value}=await rd.read();if(done)break;
           try{window.__frames.push(dec.decode(value,{stream:true}));}catch(_){}} })();
         return new Response(x,{headers:res.headers,status:res.status});
       }
     }catch(_){}
     return res;
   };
 });
 await p.setCookie({name:'MoodleSession',value:process.env.SOLA_COOKIE,domain:'degrees.saylor.org',path:'/',httpOnly:true,secure:true});
 await p.goto('https://degrees.saylor.org/mod/page/view.php?id=20059',{waitUntil:'domcontentloaded'});
 await sleep(3500);
 await p.click('.local-ai-course-assistant__toggle'); await sleep(3000);
 await p.waitForSelector('.local-ai-course-assistant__input',{timeout:20000});
 await p.click('.local-ai-course-assistant__input');
 await p.type('.local-ai-course-assistant__input','What is strategic management?',{delay:8});
 await p.click('.local-ai-course-assistant__btn-send');
 for(let i=0;i<70;i++){ await sleep(2000);
   const s=await p.evaluate(()=>[...document.querySelectorAll('button')].some(b=>/^\s*(Stop|Cancel)\s*$/i.test(b.textContent||'')));
   if(!s && i>3){break;} }
 await sleep(1500);
 const out=await p.evaluate(()=>({n:(window.__frames||[]).length, all:(window.__frames||[]).join('')}));
 fs.writeFileSync((process.env.TAP_OUT||'/tmp/sse-tap.txt'), out.all);
 console.log('frames captured:', out.n, 'bytes:', out.all.length);
 console.log('SOURCE present ->', /\[{1,3}\s*SOURCE\s*:/i.test(out.all));
 console.log('SOLA_NEXT present ->', /SOLA_NEXT/i.test(out.all));
 const m=out.all.match(/\[{1,3}\s*SOURCE\s*:[^\]]{0,40}\]{1,3}/i);
 console.log('marker:', m?m[0]:'(none)');
 // Does the model emit the inline chunk citation at all? The deterministic
 // half of the v7.5.0 source-attribution fix derives the pill from [[c:N]],
 // so if this is false on production the prompt half is all there is.
 const cites=(out.all.match(/\[\[c:\d+\]\]/g)||[]);
 console.log('[[c:N]] count ->', cites.length, cites.slice(0,6).join(' '));
 console.log('pill:', await p.evaluate(()=>{const s=document.querySelector('.aica-source-pill');return s?s.outerHTML.slice(0,140):null;}));
 await b.close();
})().catch(e=>{console.error('ERR',e.message);process.exit(1)});
