const Socket = (() => {
    let ws=null, handlers={};
    function connect(host='localhost', port=8080){
        return new Promise((res,rej)=>{
            if(ws&&ws.readyState===1){ res(); return; }
            ws=new WebSocket(`ws://${host}:${port}`);
            ws.onopen   =()=>{ console.log('[WS] Connected'); trigger('ws_open'); res(); };
            ws.onclose  =()=>{ console.log('[WS] Closed'); trigger('disconnected'); ws=null; };
            ws.onerror  =(e)=>{ rej(e); };
            ws.onmessage=(e)=>{ try{ const d=JSON.parse(e.data); console.log('[WS] ←',d.type,d); trigger(d.type,d); }catch(err){} };
        });
    }
    function send(obj){ if(ws&&ws.readyState===1) ws.send(JSON.stringify(obj)); }
    function on(t,fn){ if(!handlers[t]) handlers[t]=[]; handlers[t].push(fn); }
    function trigger(t,d={}){ (handlers[t]||[]).forEach(fn=>fn(d)); }
    return{connect,send,on};
})();
