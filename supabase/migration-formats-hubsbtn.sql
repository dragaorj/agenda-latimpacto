-- ============================================================
-- Migração: Formatos editáveis (3 idiomas) + botão "Todos os Hubs"
-- Rode UMA vez no SQL Editor do Supabase (seu banco já existe).
-- Seguro rodar de novo (idempotente).
--
-- Sem esta migração, editar os Formatos ou ligar/desligar o botão
-- "Todos os Hubs" parece funcionar na hora, mas AO RECARREGAR A PÁGINA
-- a mudança volta ao padrão — porque essas colunas não existiam na
-- tabela config e o servidor descartava esses dois campos ao salvar.
-- ============================================================

-- 1) novas colunas na config
alter table public.config
  add column if not exists formats jsonb default '[]'::jsonb;
alter table public.config
  add column if not exists show_all_hubs_btn boolean default true;

-- 2) sb_save_config passa a gravar os dois campos novos
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
    formats    = coalesce(p_config->'formats', formats),
    show_all_hubs_btn = coalesce((p_config->>'showAllHubsBtn')::boolean, show_all_hubs_btn),
    updated_at = now()
  where id = 1;
end; $$;

-- pronto. Recarregue a plataforma.
