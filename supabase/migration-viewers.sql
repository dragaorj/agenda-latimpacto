-- ============================================================
-- Migração: contador "pessoas online agora" (visível para todos)
-- Rode UMA vez no SQL Editor do Supabase. Seguro rodar de novo.
-- ============================================================

create table if not exists public.viewers (
  id        text primary key,
  last_seen timestamptz not null default now()
);
alter table public.viewers enable row level security;
-- sem policies: acesso só via a função abaixo (security definer)

-- Registra a presença do visitante e devolve quantos estão online
-- (ativos nos últimos 60s). Também limpa registros antigos.
create or replace function public.sb_view_ping(p_id text)
returns integer language plpgsql security definer set search_path=public as $$
declare n integer;
begin
  if p_id is null or length(p_id) < 4 or length(p_id) > 64 then return 0; end if;
  insert into public.viewers(id,last_seen) values (p_id, now())
    on conflict (id) do update set last_seen = now();
  delete from public.viewers where last_seen < now() - interval '1 day';
  select count(*) into n from public.viewers where last_seen > now() - interval '60 seconds';
  return n;
end; $$;

grant execute on function public.sb_view_ping(text) to anon, authenticated;

-- pronto. Recarregue a plataforma.
