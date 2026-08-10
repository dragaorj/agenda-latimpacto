/* ============================================================
   supabase-sync.js  —  camada de sincronização Latimpacto
   Liga o app ao Supabase: load / save por entidade / realtime /
   login por token / upload de imagem no Storage.

   Tudo fica em window.SB. Se não houver URL+chave (sb-config.js),
   SB.enabled = false e o app continua 100% local (preview).
   ============================================================ */
(function(){
  var cfg = window.SB_CONFIG || {};
  var URL = (cfg.url || '').trim();
  var KEY = (cfg.anonKey || '').trim();
  var enabled = !!(URL && KEY && window.supabase && /^https:\/\/.+\.supabase\.co/.test(URL));

  var client = enabled ? window.supabase.createClient(URL, KEY, {
    auth: { persistSession:false },
    realtime: { params:{ eventsPerSecond: 5 } }
  }) : null;

  // token atual (definido no login bem-sucedido)
  var token = null;

  function _rpc(fn, args){
    return client.rpc(fn, args).then(function(res){
      if(res.error) throw res.error;
      return res.data;
    });
  }

  // ---- conversão linha do banco -> formato do app ----
  function rowToTalk(r){
    return { id:r.id, day:r.day, time:r.time, room:r.room, hub:r.hub,
      format:r.format, duration:r.duration,
      speakerIds:r.speaker_ids||[], speakerName:r.speaker_name||'', speakersPending:!!r.speakers_pending,
      langs:r.langs||[],
      title:r.title||{}, desc:r.descr||{}, locImg:r.loc_img||'' };
  }
  // Campos multilíngues ({pt,en,es}) são gravados em colunas TEXT — chegam
  // como string JSON. Converte de volta para objeto; texto simples passa reto.
  function _mlParse(v){
    if(typeof v==='string' && v.charAt(0)==='{' && v.indexOf('"')>-1){
      try{ var o=JSON.parse(v); if(o && typeof o==='object' && !Array.isArray(o)) return o; }catch(e){}
    }
    return v;
  }
  function rowToSpeaker(r){
    return { id:r.id, name:r.name, role:_mlParse(r.role), company:_mlParse(r.company),
      bio:_mlParse(r.bio), photo:r.photo, linkedin:r.linkedin, sort:r.sort, category:r.category||'speaker' };
  }

  var SB = {
    enabled: enabled,
    client: client,
    setToken: function(t){ token = t; },
    clearToken: function(){ token = null; },

    // -------- LOGIN (valida token no banco) --------
    // resolve(profileObj) se válido; resolve(null) se inválido.
    login: function(tok){
      if(!enabled) return Promise.resolve(null);
      return _rpc('sb_login', { p_token: tok }).then(function(p){
        if(p){ token = tok; }
        return p; // {id,first,last,email,phone,photo,role} ou null
      });
    },

    // -------- LOAD inicial (tudo) --------
    // resolve({ speakers, talks, config }) — config = {rooms,hubs,hubColors,slots,days,highlights}
    loadAll: function(){
      if(!enabled) return Promise.resolve(null);
      return Promise.all([
        client.from('config').select('*').eq('id',1).single(),
        client.from('speakers').select('*').order('sort',{ascending:true}),
        client.from('talks').select('*')
      ]).then(function(r){
        var cfgRow = r[0].data || {};
        var spk = (r[1].data||[]).map(rowToSpeaker);
        var tk  = (r[2].data||[]).map(rowToTalk);
        return {
          speakers: spk,
          talks: tk,
          config: {
            rooms: cfgRow.rooms||[], hubs: cfgRow.hubs||[], hubColors: cfgRow.hub_colors||[],
            slots: cfgRow.slots||[], days: cfgRow.days||[], highlights: cfgRow.highlights||null,
            categories: cfgRow.categories||[], formats: cfgRow.formats||[],
            showAllHubsBtn: (cfgRow.show_all_hubs_btn!==false),
            disclaimer: cfgRow.disclaimer||null
          }
        };
      });
    },

    // -------- ESCRITAS (via RPC, token validado) --------
    saveConfig:   function(c){ return enabled ? _rpc('sb_save_config',{p_token:token,p_config:c}) : Promise.resolve(); },
    saveHighlights:function(h){
      if(!enabled) return Promise.resolve();
      var hh = h ? JSON.parse(JSON.stringify(h)) : {};
      var pre = (hh.hero && typeof hh.hero.image==='string' && hh.hero.image.indexOf('data:')===0)
        ? SB.uploadDataUrl(hh.hero.image,'hero').then(function(u){ hh.hero.image=u; }) : Promise.resolve();
      return pre.then(function(){ return _rpc('sb_save_highlights',{p_token:token,p_h:hh}); });
    },
    upsertTalk:   function(t){
      if(!enabled) return Promise.resolve();
      var tt = JSON.parse(JSON.stringify(t));
      var pre = (typeof tt.locImg==='string' && tt.locImg.indexOf('data:')===0)
        ? SB.uploadDataUrl(tt.locImg,'talks').then(function(u){ tt.locImg=u; }) : Promise.resolve();
      return pre.then(function(){ return _rpc('sb_upsert_talk',{p_token:token,p_t:tt}); });
    },
    deleteTalk:   function(id){ return enabled ? _rpc('sb_delete_talk',{p_token:token,p_id:id}) : Promise.resolve(); },
    upsertSpeaker:function(s){
      if(!enabled) return Promise.resolve();
      var ss = JSON.parse(JSON.stringify(s));
      var pre = (typeof ss.photo==='string' && ss.photo.indexOf('data:')===0)
        ? SB.uploadDataUrl(ss.photo,'speakers').then(function(u){ ss.photo=u; }) : Promise.resolve();
      return pre.then(function(){ return _rpc('sb_upsert_speaker',{p_token:token,p_s:ss}); });
    },
    deleteSpeaker:function(id){ return enabled ? _rpc('sb_delete_speaker',{p_token:token,p_id:id}) : Promise.resolve(); },
    log:          function(action,detail){ return enabled ? _rpc('sb_log',{p_token:token,p_action:action,p_detail:detail}).catch(function(){}) : Promise.resolve(); },
    getLogs:      function(){ return enabled ? _rpc('sb_get_logs',{p_token:token}) : Promise.resolve([]); },
    clearLogs:    function(){ return enabled ? _rpc('sb_clear_logs',{p_token:token}) : Promise.resolve(); },
    listProfiles: function(){ return enabled ? _rpc('sb_list_profiles',{p_token:token}) : Promise.resolve([]); },
    upsertProfile:function(p){
      if(!enabled) return Promise.resolve();
      var pp = JSON.parse(JSON.stringify(p));
      var pre = (typeof pp.photo==='string' && pp.photo.indexOf('data:')===0)
        ? SB.uploadDataUrl(pp.photo,'profiles').then(function(u){ pp.photo=u; }) : Promise.resolve();
      return pre.then(function(){ return _rpc('sb_upsert_profile',{p_token:token,p_p:pp}); });
    },
    deleteProfile:function(id){ return enabled ? _rpc('sb_delete_profile',{p_token:token,p_id:id}) : Promise.resolve(); },
    wipe:         function(){ return enabled ? _rpc('sb_wipe',{p_token:token}) : Promise.resolve(); },

    // -------- RESTORE de backup completo (apaga tudo e reenvia) --------
    // data = { speakers:[], schedules:[ {time:{room:talk}} ], slots, rooms, hubs, hubColors, days, highlights, categories }
    restoreAll: function(data){
      if(!enabled) return Promise.resolve();
      var self = this;
      // 1) achata schedules -> lista de talks
      var talks = [];
      try{
        (data.schedules||[]).forEach(function(day, di){
          Object.keys(day||{}).forEach(function(time){
            var slot = day[time]||{};
            Object.keys(slot).forEach(function(room){
              var t = slot[room]; if(t && typeof t==='object'){ t.day=di; t.time=t.time||time; t.room=t.room||room; talks.push(t); }
            });
          });
        });
      }catch(e){}
      var speakers = data.speakers||[];
      // 2) wipe -> config -> speakers -> talks (sequencial p/ FKs e ordem)
      return _rpc('sb_wipe',{p_token:token}).then(function(){
        return self.saveConfig({
          rooms:data.rooms||[], hubs:data.hubs||[], hubColors:data.hubColors||[],
          slots:data.slots||[], days:data.days||[], highlights:data.highlights||null,
          categories:data.categories||[]
        });
      }).then(function(){
        return speakers.reduce(function(p, s){ return p.then(function(){ return self.upsertSpeaker(s); }); }, Promise.resolve());
      }).then(function(){
        return talks.reduce(function(p, t){ return p.then(function(){ return self.upsertTalk(t); }); }, Promise.resolve());
      });
    },

    // -------- UPLOAD de imagem (dataURL -> Storage) --------
    // resolve(publicUrl). Em caso de falha ou SB off, resolve(dataUrl) (fallback embutido).
    uploadDataUrl: function(dataUrl, folder){
      if(!enabled || !dataUrl || dataUrl.indexOf('data:')!==0) return Promise.resolve(dataUrl);
      try{
        var parts = dataUrl.split(','), mime=(parts[0].match(/data:(.*?);/)||[])[1]||'image/webp';
        var bin = atob(parts[1]); var arr = new Uint8Array(bin.length);
        for(var i=0;i<bin.length;i++) arr[i]=bin.charCodeAt(i);
        var ext = mime.indexOf('webp')>-1?'webp':(mime.indexOf('png')>-1?'png':'jpg');
        var path = (folder||'img')+'/'+Date.now()+'-'+Math.random().toString(36).slice(2,8)+'.'+ext;
        return client.storage.from('agenda').upload(path, new Blob([arr],{type:mime}), {contentType:mime, upsert:true})
          .then(function(res){
            if(res.error) throw res.error;
            return client.storage.from('agenda').getPublicUrl(path).data.publicUrl;
          }).catch(function(){ return dataUrl; });
      }catch(e){ return Promise.resolve(dataUrl); }
    },

    // -------- REALTIME --------
    // onChange: callback({table, eventType}) quando talks/speakers/config mudam.
    subscribe: function(onChange){
      if(!enabled) return null;
      var ch = client.channel('agenda-rt')
        .on('postgres_changes',{event:'*',schema:'public',table:'talks'},   function(p){ onChange({table:'talks',eventType:p.eventType}); })
        .on('postgres_changes',{event:'*',schema:'public',table:'speakers'},function(p){ onChange({table:'speakers',eventType:p.eventType}); })
        .on('postgres_changes',{event:'*',schema:'public',table:'config'},  function(p){ onChange({table:'config',eventType:p.eventType}); })
        .subscribe();
      return ch;
    },
    heartbeat: function(){ return enabled ? _rpc('sb_heartbeat',{p_token:token}).catch(function(){}) : Promise.resolve(); },
    onlineProfiles: function(){ return enabled ? _rpc('sb_online_profiles',{p_token:token}) : Promise.resolve([]); }
  };

  window.SB = SB;
  try{ console.log('[SB] Supabase '+(enabled?'CONECTADO ('+URL+')':'desligado — modo local')); }catch(e){}
})();
