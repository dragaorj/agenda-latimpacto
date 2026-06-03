-- ============================================================
-- Agenda Latimpacto / Impact Minds: Connecting Us
-- Esquema Supabase  —  modelo NORMALIZADO
--   - leitura pública (viewer)
--   - escrita só via RPC validando TOKEN (perfis)
--   - imagens no Storage (bucket "agenda")
--   - realtime em talks / speakers / config
--
-- Como usar: Supabase  ->  SQL Editor  ->  cole tudo  ->  Run.
-- (idempotente: pode rodar de novo sem quebrar)
-- ============================================================

create extension if not exists pgcrypto;

-- ------------------------------------------------------------
-- TABELAS
-- ------------------------------------------------------------

-- Perfis de edição. O TOKEN é a senha de cada um.
create table if not exists public.profiles (
  id         text primary key,
  first      text not null default '',
  last       text default '',
  email      text default '',
  phone      text default '',
  photo      text default '',                 -- URL no Storage
  token      text not null unique,
  role       text not null default 'editor',  -- 'master' | 'editor'
  created_at timestamptz default now()
);

-- Palestrantes
create table if not exists public.speakers (
  id         text primary key,
  name       text not null default '',
  role       text default '',
  company    text default '',
  bio        text default '',
  photo      text default '',                 -- URL no Storage
  linkedin   text default '',
  sort       int default 0,
  updated_at timestamptz default now()
);

-- Palestras / sessões
create table if not exists public.talks (
  id           text primary key,
  day          int  not null default 0,
  "time"       text not null default '',      -- 'HH:MM' (rótulo do slot)
  room         text default '',               -- id da sala
  hub          text default '',               -- índice do hub (string, como no app)
  format       text default '',
  duration     int  default 30,
  speaker_ids  jsonb default '[]'::jsonb,
  speaker_name text default '',
  title        jsonb default '{}'::jsonb,      -- {pt,en,es}
  descr        jsonb default '{}'::jsonb,      -- {pt,en,es}
  loc_img      text default '',
  updated_at   timestamptz default now()
);

-- Configuração (registro único id=1): salas, hubs, cores, slots, dias, destaques
create table if not exists public.config (
  id         int primary key default 1,
  rooms      jsonb default '[]'::jsonb,
  hubs       jsonb default '[]'::jsonb,
  hub_colors jsonb default '[]'::jsonb,
  slots      jsonb default '[]'::jsonb,
  days       jsonb default '[]'::jsonb,
  highlights jsonb default '{}'::jsonb,
  updated_at timestamptz default now(),
  constraint config_singleton check (id = 1)
);
insert into public.config (id) values (1) on conflict (id) do nothing;

-- Logs de auditoria (visíveis só para o master, via RPC)
create table if not exists public.logs (
  id     bigint generated always as identity primary key,
  ts     timestamptz default now(),
  who    text default '',
  "role" text default '',
  action text default '',
  detail text default ''
);

-- Perfil MASTER inicial (token = senha mestra do app).
-- Troque o token depois pelo painel de Perfis, se quiser.
insert into public.profiles (id, first, last, token, role)
values ('master', 'Admin', 'Master', 'dragaoninja', 'master')
on conflict (id) do nothing;

-- ------------------------------------------------------------
-- HELPER: valida token -> retorna o perfil (ou nulo)
-- ------------------------------------------------------------
create or replace function public._profile_by_token(p_token text)
returns public.profiles
language sql stable security definer set search_path = public as $$
  select * from public.profiles where token = p_token limit 1;
$$;

-- ------------------------------------------------------------
-- RLS — leitura pública nas tabelas de conteúdo; escrita bloqueada
--       (toda escrita passa pelas RPCs abaixo, security definer)
-- ------------------------------------------------------------
alter table public.profiles enable row level security;
alter table public.speakers enable row level security;
alter table public.talks    enable row level security;
alter table public.config   enable row level security;
alter table public.logs     enable row level security;

-- conteúdo: SELECT liberado para anon (viewer)
drop policy if exists p_read_speakers on public.speakers;
create policy p_read_speakers on public.speakers for select using (true);

drop policy if exists p_read_talks on public.talks;
create policy p_read_talks on public.talks for select using (true);

drop policy if exists p_read_config on public.config;
create policy p_read_config on public.config for select using (true);

-- profiles e logs: SEM select público (tokens e auditoria são sensíveis).
-- São lidos apenas via RPC (sb_login / sb_list_profiles / sb_get_logs).

-- ------------------------------------------------------------
-- RPCs DE ESCRITA  (validam token; rodam como definer)
-- ------------------------------------------------------------

-- LOGIN: devolve o perfil SEM o token
create or replace function public.sb_login(p_token text)
returns jsonb language plpgsql stable security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then return null; end if;
  return jsonb_build_object('id',pr.id,'first',pr.first,'last',pr.last,
    'email',pr.email,'phone',pr.phone,'photo',pr.photo,'role',pr.role);
end; $$;

-- LOG (auditoria). who/role são resolvidos do token (não confia no cliente).
create or replace function public.sb_log(p_token text, p_action text, p_detail text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.logs(who,"role",action,detail)
  values (trim(coalesce(pr.first,'')||' '||coalesce(pr.last,'')), pr.role, coalesce(p_action,''), coalesce(p_detail,''));
end; $$;

-- LISTAR LOGS (apenas master)
create or replace function public.sb_get_logs(p_token text)
returns setof public.logs language plpgsql stable security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null or pr.role <> 'master' then raise exception 'apenas master'; end if;
  return query select * from public.logs order by ts desc limit 800;
end; $$;

create or replace function public.sb_clear_logs(p_token text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null or pr.role <> 'master' then raise exception 'apenas master'; end if;
  delete from public.logs;
end; $$;

-- SALVAR CONFIG (salas, hubs, cores, slots, dias, destaques)
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
    updated_at = now()
  where id = 1;
end; $$;

-- SALVAR só os DESTAQUES
create or replace function public.sb_save_highlights(p_token text, p_h jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  update public.config set highlights = coalesce(p_h,'{}'::jsonb), updated_at = now() where id = 1;
end; $$;

-- UPSERT PALESTRANTE
create or replace function public.sb_upsert_speaker(p_token text, p_s jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.speakers(id,name,"role",company,bio,photo,linkedin,sort,updated_at)
  values (
    coalesce(p_s->>'id', gen_random_uuid()::text),
    coalesce(p_s->>'name',''), coalesce(p_s->>'role',''), coalesce(p_s->>'company',''),
    coalesce(p_s->>'bio',''),  coalesce(p_s->>'photo',''), coalesce(p_s->>'linkedin',''),
    coalesce((p_s->>'sort')::int,0), now())
  on conflict (id) do update set
    name=excluded.name, "role"=excluded."role", company=excluded.company, bio=excluded.bio,
    photo=excluded.photo, linkedin=excluded.linkedin, sort=excluded.sort, updated_at=now();
end; $$;

create or replace function public.sb_delete_speaker(p_token text, p_id text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  delete from public.speakers where id = p_id;
end; $$;

-- UPSERT PALESTRA
create or replace function public.sb_upsert_talk(p_token text, p_t jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  insert into public.talks(id,day,"time",room,hub,format,duration,speaker_ids,speaker_name,title,descr,loc_img,updated_at)
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
    coalesce(p_t->'title','{}'::jsonb),
    coalesce(p_t->'desc','{}'::jsonb),
    coalesce(p_t->>'locImg',''),
    now())
  on conflict (id) do update set
    day=excluded.day, "time"=excluded."time", room=excluded.room, hub=excluded.hub,
    format=excluded.format, duration=excluded.duration, speaker_ids=excluded.speaker_ids,
    speaker_name=excluded.speaker_name, title=excluded.title, descr=excluded.descr,
    loc_img=excluded.loc_img, updated_at=now();
end; $$;

create or replace function public.sb_delete_talk(p_token text, p_id text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  delete from public.talks where id = p_id;
end; $$;

-- PERFIS (somente master)
create or replace function public.sb_list_profiles(p_token text)
returns setof public.profiles language plpgsql stable security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null or pr.role <> 'master' then raise exception 'apenas master'; end if;
  return query select * from public.profiles order by created_at;
end; $$;

create or replace function public.sb_upsert_profile(p_token text, p_p jsonb)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null or pr.role <> 'master' then raise exception 'apenas master'; end if;
  insert into public.profiles(id,first,last,email,phone,photo,token,"role")
  values (
    coalesce(p_p->>'id', gen_random_uuid()::text),
    coalesce(p_p->>'first',''), coalesce(p_p->>'last',''), coalesce(p_p->>'email',''),
    coalesce(p_p->>'phone',''), coalesce(p_p->>'photo',''), p_p->>'token',
    coalesce(p_p->>'role','editor'))
  on conflict (id) do update set
    first=excluded.first, last=excluded.last, email=excluded.email, phone=excluded.phone,
    photo=excluded.photo, token=excluded.token, "role"=excluded."role";
end; $$;

create or replace function public.sb_delete_profile(p_token text, p_id text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null or pr.role <> 'master' then raise exception 'apenas master'; end if;
  if p_id = 'master' then raise exception 'não é possível excluir o master'; end if;
  delete from public.profiles where id = p_id;
end; $$;

-- APAGAR TUDO (somente master) — zera conteúdo, mantém perfis
create or replace function public.sb_wipe(p_token text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null or pr.role <> 'master' then raise exception 'apenas master'; end if;
  delete from public.talks;
  delete from public.speakers;
  update public.config set highlights='{}'::jsonb, updated_at=now() where id=1;
end; $$;

-- ------------------------------------------------------------
-- Permissões de execução das RPCs para anon (chave pública)
-- ------------------------------------------------------------
grant execute on function
  public.sb_login(text),
  public.sb_log(text,text,text),
  public.sb_get_logs(text),
  public.sb_clear_logs(text),
  public.sb_save_config(text,jsonb),
  public.sb_save_highlights(text,jsonb),
  public.sb_upsert_speaker(text,jsonb),
  public.sb_delete_speaker(text,text),
  public.sb_upsert_talk(text,jsonb),
  public.sb_delete_talk(text,text),
  public.sb_list_profiles(text),
  public.sb_upsert_profile(text,jsonb),
  public.sb_delete_profile(text,text),
  public.sb_wipe(text)
to anon, authenticated;

-- ------------------------------------------------------------
-- REALTIME — propaga mudanças de conteúdo aos clientes
-- ------------------------------------------------------------
alter publication supabase_realtime add table public.talks;
alter publication supabase_realtime add table public.speakers;
alter publication supabase_realtime add table public.config;

-- ------------------------------------------------------------
-- STORAGE — bucket público "agenda" (fotos e capa)
-- ------------------------------------------------------------
insert into storage.buckets (id, name, public)
values ('agenda','agenda', true)
on conflict (id) do nothing;

-- leitura pública do bucket
drop policy if exists p_agenda_read on storage.objects;
create policy p_agenda_read on storage.objects
  for select using (bucket_id = 'agenda');

-- upload/atualização pela chave anon (navegador dos editores)
-- (pragmático: imagens não são sensíveis; restringe ao bucket "agenda")
drop policy if exists p_agenda_insert on storage.objects;
create policy p_agenda_insert on storage.objects
  for insert with check (bucket_id = 'agenda');

drop policy if exists p_agenda_update on storage.objects;
create policy p_agenda_update on storage.objects
  for update using (bucket_id = 'agenda');

-- ============================================================
-- Fim. Próximo: copie a URL e a chave anon (Settings -> API)
-- para o arquivo sb-config.js do app.
-- ============================================================
