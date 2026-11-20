/* Restricted kitchen grammar; never interprets arbitrary speech as server commands. */
(function (global) {
    'use strict';
    function thaiNumber(text) {
        text=text.trim().replace(/[๐-๙]/g,c=>String('๐๑๒๓๔๕๖๗๘๙'.indexOf(c)));
        if(/^\d{1,5}$/.test(text))return Number(text);
        const digits={'ศูนย์':0,'หนึ่ง':1,'เอ็ด':1,'สอง':2,'ยี่':2,'สาม':3,'สี่':4,'ห้า':5,'หก':6,'เจ็ด':7,'แปด':8,'เก้า':9};
        const tokens=text.match(/ศูนย์|หนึ่ง|เอ็ด|สอง|ยี่|สาม|สี่|ห้า|หก|เจ็ด|แปด|เก้า|หมื่น|พัน|ร้อย|สิบ/g);
        if(!tokens || tokens.join('')!==text)return NaN;
        let total=0,current=0;
        for(const t of tokens){if(Object.hasOwn(digits,t))current=digits[t];else {const unit={'สิบ':10,'ร้อย':100,'พัน':1000,'หมื่น':10000}[t];total+=(current||1)*unit;current=0;}}
        return total+current;
    }
    function parse(text) {
        const normalized=text.trim().toUpperCase().replace(/\s+/g,'');
        if(normalized==='ยืนยัน')return {confirm:true};
        if(normalized==='ยกเลิก')return {cancel:true};
        const match=normalized.match(/^(เริ่มทำ|พร้อมรับ)(?:คิว)?(ดับเบิลยู|เอ|บี|ซี|เค|โอ|[ABCKOW])(.+)$/);
        if(!match)return null;
        const prefix=({'เอ':'A','บี':'B','ซี':'C','เค':'K','โอ':'O','ดับเบิลยู':'W'})[match[2]]||match[2];
        const number=thaiNumber(match[3]);
        if(!Number.isInteger(number)||number<1||number>65535)return null;
        return {label:prefix+String(number).padStart(3,'0'),status:match[1]==='เริ่มทำ'?'cooking':'ready'};
    }
    global.KitchenVoiceParser={parse,thaiNumber};
    if(typeof document==='undefined')return;
    const start=document.getElementById('kitchen-listen'),stop=document.getElementById('kitchen-stop'),status=document.getElementById('kitchen-voice-status');
    if(!start)return;
    const SR=global.SpeechRecognition||global.webkitSpeechRecognition;
    if(!SR){start.disabled=true;status.textContent='เบราว์เซอร์นี้ไม่รองรับเสียง กรุณาใช้ปุ่มบนใบงาน';return;}
    let recognition=null,pending=null,pendingTimer=null;
    function clearPending(){pending=null;clearTimeout(pendingTimer);}
    function halt(){global.kitchenVoiceActive=false;clearPending();if(recognition)recognition.stop();recognition=null;}
    function handle(text){
        const command=parse(text);
        if(!command){clearPending();status.textContent='ไม่เข้าใจคำสั่ง: '+text+' — ยังไม่เปลี่ยนสถานะ';return;}
        if(command.cancel){clearPending();status.textContent='ยกเลิกคำสั่งแล้ว';return;}
        if(command.confirm){
            if(!pending){status.textContent='ไม่มีคำสั่งรอยืนยัน';return;}
            const form=pending.form;
            clearPending();
            const source=document.createElement('input');source.type='hidden';source.name='input_source';source.value='voice';form.appendChild(source);
            halt();form.requestSubmit();return;
        }
        const forms=Array.from(document.querySelectorAll('form[data-voice-ticket]')).filter(f=>f.dataset.voiceTicket===command.label&&f.dataset.next===command.status);
        if(forms.length!==1){clearPending();status.textContent='ไม่พบคิวที่เปลี่ยนสถานะตามคำสั่งได้เพียงหนึ่งใบงาน กรุณาตรวจหน้าจอ';return;}
        pending={form:forms[0]};
        status.textContent=(command.status==='cooking'?'เริ่มทำ ':'ทำเสร็จ ')+command.label+' — พูด “ยืนยัน” ภายใน 15 วินาที หรือ “ยกเลิก”';
        clearTimeout(pendingTimer);pendingTimer=setTimeout(()=>{clearPending();status.textContent='หมดเวลายืนยัน ยังไม่เปลี่ยนสถานะ';},15000);
    }
    start.addEventListener('click',()=>{
        if(recognition)return;
        const r=new SR();recognition=r;r.lang='th-TH';r.continuous=true;r.interimResults=false;
        r.onstart=()=>{global.kitchenVoiceActive=true;status.textContent='กำลังฟัง — เช่น เริ่มทำ เค 6';};
        r.onresult=e=>{for(let i=e.resultIndex;i<e.results.length;i++)if(e.results[i].isFinal)handle(e.results[i][0].transcript);};
        r.onerror=e=>{halt();status.textContent='รับเสียงไม่ได้ ('+e.error+') กรุณาตรวจไมค์/HTTPS และใช้ปุ่มสำรอง';};
        r.onend=()=>{recognition=null;global.kitchenVoiceActive=false;clearPending();};
        try{r.start();}catch(e){halt();status.textContent='เปิดไมโครโฟนไม่สำเร็จ';}
    });
    stop.addEventListener('click',()=>{halt();status.textContent='ปิดไมโครโฟนแล้ว';});
    addEventListener('pagehide',halt);
})(typeof window!=='undefined'?window:globalThis);
