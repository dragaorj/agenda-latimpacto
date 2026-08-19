-- ============================================================
-- Migração: MODERADOR (destaque com anel em degradê + seção própria)
-- Rode UMA vez no SQL Editor do Supabase. Seguro rodar de novo.
-- ============================================================

alter table public.speakers
  add column if not exists moderator boolean default false;

create or replace function public.sb_upsert_speaker(p_token text, p_s jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.speakers(id,name,"role",company,bio,photo,linkedin,category,moderator,sort,updated_at)
  values (
    coalesce(p_s->>'id', gen_random_uuid()::text),
    coalesce(p_s->>'name',''), coalesce(p_s->>'role',''), coalesce(p_s->>'company',''),
    coalesce(p_s->>'bio',''),  coalesce(p_s->>'photo',''), coalesce(p_s->>'linkedin',''),
    coalesce(p_s->>'category','speaker'),
    coalesce((p_s->>'moderator')::boolean,false),
    coalesce((p_s->>'sort')::int,0), now())
  on conflict (id) do update set
    name=excluded.name, "role"=excluded."role", company=excluded.company, bio=excluded.bio,
    photo=excluded.photo, linkedin=excluded.linkedin, category=excluded.category,
    moderator=excluded.moderator, sort=excluded.sort, updated_at=now();
end; $$;

-- pronto. Recarregue a plataforma.
