-- ============================================================
-- Migração: Presença online (avatares de quem está logado agora)
-- Rode UMA vez no SQL Editor do Supabase (seu banco já existe).
-- Seguro rodar de novo (idempotente).
-- ============================================================

alter table public.profiles
  add column if not exists last_seen timestamptz default null;

create or replace function public.sb_heartbeat(p_token text)
returns void language plpgsql security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  update public.profiles set last_seen = now() where id = pr.id;
end; $$;

create or replace function public.sb_online_profiles(p_token text)
returns table(id text, first text, last text, photo text) language plpgsql stable security definer set search_path=public as $$
declare pr public.profiles;
begin
  pr := public._profile_by_token(p_token);
  if pr.id is null then raise exception 'token inválido'; end if;
  return query select p.id, p.first, p.last, p.photo from public.profiles p
    where p.last_seen is not null and p.last_seen > now() - interval '45 seconds'
    order by p.last_seen desc;
end; $$;

grant execute on function
  public.sb_heartbeat(text),
  public.sb_online_profiles(text)
to anon, authenticated;

-- pronto. Recarregue a plataforma.
