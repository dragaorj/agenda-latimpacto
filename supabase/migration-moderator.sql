-- ============================================================
-- Migração: MODERADOR POR SESSÃO (anel em degradê + seção própria)
-- Rode UMA vez no SQL Editor do Supabase. Seguro rodar de novo.
-- ============================================================

alter table public.talks
  add column if not exists moderator_id text default null;

create or replace function public.sb_upsert_talk(p_token text, p_t jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.talks(id,day,"time",room,hub,format,duration,speaker_ids,speaker_name,speakers_pending,moderator_id,langs,title,descr,loc_img,updated_at)
  values (
    coalesce(p_t->>'id', gen_random_uuid()::text),
    coalesce((p_t->>'day')::int,0),
    coalesce(p_t->>'time',''),
    coalesce(p_t->>'room',''),
    coalesce(p_t->>'hub',''),
    coalesce(p_t->>'format',''),
    coalesce((p_t->>'duration')::int,30),
    coalesce(p_t->'speakerIds','[]'::jsonb),
    coalesce(p_t->>'speakerName',''),
    coalesce((p_t->>'speakersPending')::boolean,false),
    nullif(p_t->>'moderatorId',''),
    coalesce(p_t->'langs','[]'::jsonb),
    coalesce(p_t->'title','{}'::jsonb),
    coalesce(p_t->'desc','{}'::jsonb),
    coalesce(p_t->>'locImg',''),
    now())
  on conflict (id) do update set
    day=excluded.day, "time"=excluded."time", room=excluded.room, hub=excluded.hub,
    format=excluded.format, duration=excluded.duration, speaker_ids=excluded.speaker_ids,
    speaker_name=excluded.speaker_name, speakers_pending=excluded.speakers_pending,
    moderator_id=excluded.moderator_id, langs=excluded.langs, title=excluded.title, descr=excluded.descr,
    loc_img=excluded.loc_img, updated_at=now();
end; $$;

-- pronto. Recarregue a plataforma.
