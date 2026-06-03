# Agenda Latimpacto — Supabase + Vercel

Guia para colocar a agenda no ar com **dados reais, login por token, edição
simultânea (tempo real)** e imagens no **Storage**.

> Enquanto você não preencher a chave `anon` em `sb-config.js`, o app roda em
> **modo local** (dados de exemplo) — nada quebra. Assim dá pra publicar o
> visual antes de ligar o banco.

---

## 1. Criar o projeto no Supabase
1. Acesse https://supabase.com → **New project**.
2. Escolha nome, senha do banco e região (use uma próxima da América Latina, ex.: *South America (São Paulo)*).
3. Aguarde o provisionamento (~2 min).

## 2. Rodar o esquema
1. No projeto: **SQL Editor** → **New query**.
2. Cole **todo** o conteúdo de [`schema.sql`](./schema.sql) e clique **Run**.
3. Isso cria tabelas, RLS, funções (RPC), realtime e o bucket `agenda`.
   - Já cria o perfil **master** com token `dragaoninja` (troque depois no painel de Perfis).

## 3. Pegar as credenciais públicas
1. **Project Settings → API**.
2. Copie **Project URL** e a chave **anon / public**.
3. No app, edite **`sb-config.js`**:
   ```js
   window.SB_CONFIG = {
     url: "https://SEU-PROJETO.supabase.co",
     anonKey: "SUA_CHAVE_ANON_PUBLIC"
   };
   ```
   > ⚠️ Use só a chave **anon (public)**. **Nunca** coloque a `service_role` no front-end.

Pronto: ao recarregar, o app mostra no console `[SB] Supabase CONECTADO` e passa a
ler/gravar no banco com tempo real.

## 4. Como a segurança funciona
- **Leitura** (viewer): pública — qualquer um vê a agenda.
- **Escrita**: só via funções RPC que **validam o token** do perfil. A chave anon
  sozinha não escreve nas tabelas (RLS bloqueia).
- **Perfis e logs**: não têm leitura pública (tokens e auditoria são sensíveis);
  só o **master** lê via RPC.
- **Imagens**: bucket `agenda` com leitura pública; upload pela chave anon
  (imagens não são dados sensíveis).

## 5. Edição simultânea (tempo real)
- Palestras e palestrantes são tabelas próprias → duas pessoas editando coisas
  diferentes **não se sobrescrevem**.
- O app assina mudanças (`talks`, `speakers`, `config`) e **recarrega ao vivo**
  quando outra pessoa salva.
- Logs do master atualizam por *polling* enquanto o painel está aberto.

---

## 6. Deploy na Vercel
O app é **estático** (HTML + JS). Não precisa de build.

### Estrutura mínima do repositório
```
/
├─ Agenda Latimpacto.html      (renomeie para index.html no deploy, ver abaixo)
├─ sb-config.js
├─ supabase-sync.js
└─ supabase/
   ├─ schema.sql
   └─ README.md
```

### Passos
1. Suba o repositório no **GitHub**.
2. Na **Vercel**: **Add New → Project** → importe o repositório.
3. **Framework Preset:** `Other`. **Build Command:** vazio. **Output Directory:** `.` (raiz).
4. **Deploy**.

> **Dica:** a Vercel serve `index.html` por padrão. Renomeie
> `Agenda Latimpacto.html` para `index.html` (ou crie um `vercel.json` com um
> rewrite). Mantenha `sb-config.js` e `supabase-sync.js` na mesma pasta.

### `vercel.json` (opcional — abre a agenda na raiz)
```json
{
  "rewrites": [{ "source": "/", "destination": "/Agenda Latimpacto.html" }]
}
```

### Domínio
- **Project → Settings → Domains** para ligar o domínio do evento.

---

## 7. Checklist pós-deploy
- [ ] Console mostra `[SB] Supabase CONECTADO`.
- [ ] Viewer abre a agenda (leitura pública).
- [ ] Login com `dragaoninja` entra no modo edição.
- [ ] Criar/editar palestra reflete em outra aba/dispositivo em segundos.
- [ ] Upload de foto/capa gera URL `...supabase.co/storage/v1/object/public/agenda/...`.
- [ ] Painel de Logs (master) registra as ações com o nome de quem fez.

## 8. Trocar a senha mestra
No app logado como master → **Conta → Perfis** → edite o perfil *master* e troque o token.
(Ou via SQL: `update public.profiles set token='novo-token' where id='master';`)
