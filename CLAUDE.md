# Projeto: Agenda Latimpacto

## Contexto
Migração/reskin do app de agenda (originalmente plugin WordPress "Impacta Mais")
para o evento **Latimpacto / Impact Minds Conference**.
Stack futura: Git + Supabase + Vercel (backend conectado em etapa posterior).
Site do evento: https://impactmindsconference.com/en/

## Design System Latimpacto
- Paleta: #F5A26C (laranja), #8135B7 (roxo vibrante), #611780 (roxo sóbrio — PRIMÁRIA),
  #FFFFFF, #B0B0B0, #727272, #0E0E0E.
- Visual mais suave que o original: cards brancos/neutros, cor do hub aparece SÓ como
  filete na borda esquerda (não fundo colorido).
- Cor primária da marca: #611780 (botões, links, abas ativas).
- Cores diferentes = apenas filete/borda fina.

## Fonte
- **Google Sans em tudo** (pesos e tamanhos a critério do designer).
- Stack: 'Google Sans','Google Sans Text', fallback próximo (DM Sans) carregado no preview.
- Números/horas com font-variant-numeric: tabular-nums.

## Evento
- 4 dias: 08/09/26, 09/09/26, 10/09/26, 11/09/26.
- Plataforma em 3 idiomas (PT/EN/ES), com edição manual das traduções de títulos/descrições.

## Modos
- Viewer: somente leitura, SEM opções de edição, mas COM botão de login (token).
- Login: token/senha única simples (como hoje).
- Views: Grade, Lista, Cards (Gantt removido).
- Manter aba Palestrantes (perfis, fotos, LinkedIn) e dark mode.
