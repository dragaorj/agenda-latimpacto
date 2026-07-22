-- ============================================================
-- Migração: tag "Em processo de confirmação" POR SESSÃO
-- (além da tag global do palestrante, agora dá para marcar um
-- palestrante como "em confirmação" só para uma sessão específica).
-- Rode UMA vez no SQL Editor do Supabase. Seguro rodar de novo.
-- ============================================================

alter table public.talks
  add column if not exists pending_spk_ids jsonb default '[]'::jsonb;

create or replace function public.sb_upsert_talk(p_token text, p_t jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.talks(id,day,"time",room,hub,format,duration,speaker_ids,speaker_name,pending_spk_ids,title,descr,loc_img,updated_at)
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
    coalesce(p_t->'pendingSpkIds','[]'::jsonb),
    coalesce(p_t->'title','{}'::jsonb),
    coalesce(p_t->'desc','{}'::jsonb),
    coalesce(p_t->>'locImg',''),
    now())
  on conflict (id) do update set
    day=excluded.day, "time"=excluded."time", room=excluded.room, hub=excluded.hub,
    format=excluded.format, duration=excluded.duration, speaker_ids=excluded.speaker_ids,
    speaker_name=excluded.speaker_name, pending_spk_ids=excluded.pending_spk_ids, title=excluded.title, descr=excluded.descr,
    loc_img=excluded.loc_img, updated_at=now();
end; $$;

-- pronto. Recarregue a plataforma.
