-- ============================================================
-- Migração: Participantes + categorias editáveis (nome + cor)
-- Rode UMA vez no SQL Editor do Supabase (seu banco já existe).
-- Seguro rodar de novo (idempotente).
-- ============================================================

-- 1) categoria do participante (texto livre: id da categoria)
alter table public.speakers
  add column if not exists category text default 'speaker';

-- 2) definições de categorias (nome + cor) ficam na config
alter table public.config
  add column if not exists categories jsonb default '[]'::jsonb;

-- 3) upsert de participante grava a categoria
create or replace function public.sb_upsert_speaker(p_token text, p_s jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.speakers(id,name,"role",company,bio,photo,linkedin,category,sort,updated_at)
  values (
    coalesce(p_s->>'id', gen_random_uuid()::text),
    coalesce(p_s->>'name',''), coalesce(p_s->>'role',''), coalesce(p_s->>'company',''),
    coalesce(p_s->>'bio',''),  coalesce(p_s->>'photo',''), coalesce(p_s->>'linkedin',''),
    coalesce(p_s->>'category','speaker'),
    coalesce((p_s->>'sort')::int,0), now())
  on conflict (id) do update set
    name=excluded.name, "role"=excluded."role", company=excluded.company, bio=excluded.bio,
    photo=excluded.photo, linkedin=excluded.linkedin, category=excluded.category, sort=excluded.sort, updated_at=now();
end; $$;

-- 4) salvar config grava as categorias
create or replace function public.sb_save_config(p_token text, p_config jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  update public.config set
    rooms      = coalesce(p_config->'rooms', rooms),
    hubs       = coalesce(p_config->'hubs', hubs),
    hub_colors = coalesce(p_config->'hubColors', hub_colors),
    slots      = coalesce(p_config->'slots', slots),
    days       = coalesce(p_config->'days', days),
    highlights = coalesce(p_config->'highlights', highlights),
    categories = coalesce(p_config->'categories', categories),
    updated_at = now()
  where id = 1;
end; $$;

-- pronto. Recarregue a plataforma.
