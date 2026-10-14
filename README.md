# TecFix — Sistema de Assistência Técnica

Sistema PHP + SQLite para gerenciamento de ordens de serviço.

## Recursos adicionados

- Upload de imagens nas OS somente quando o status é:
  - `Recebido`
  - `Em análise`
  - `Pronto`
- Imagens são validadas por MIME, tamanho (até 8 MB) e conteúdo de imagem.
- Arquivos de imagem são armazenados em `data/imagens/` com nomes aleatórios e protegidos contra execução direta.
- Técnicos não visualizam telefone, e-mail, CPF ou endereço do cliente.
- Técnicos também não têm acesso ao menu de Clientes/Nova ordem.
- Administrador e atendente continuam podendo visualizar os dados pessoais.
- Administrador possui a aba **Técnicos** para:
  - cadastrar novos técnicos;
  - retirar o acesso de técnicos;
  - reativar técnicos.
- O controle de acesso é feito no servidor, não apenas escondendo elementos da interface.
- Administrador pode excluir imagens anexadas à OS.
- Cliente visualiza a navegação lateral aberta assim que consulta uma OS, sem botão de três barras.
- A aba Loja permite adicionar produtos ao orçamento da OS e mostra estoque disponível.
- Administrador possui a aba Loja / Estoque para cadastrar, editar, ativar/desativar produtos e ajustar o estoque.
- Ao adicionar um produto ao orçamento, o estoque é baixado automaticamente; compras acima do estoque disponível são bloqueadas.

## Primeiro acesso

Na primeira execução, o banco SQLite é criado automaticamente.

- Administrador: `admin@assistencia.com` / `admin123`
- Técnico: `tecnico@assistencia.com` / `tecnico123`
- Atendente: `atendente@assistencia.com` / `atendente123`

**Troque as senhas padrão antes de usar em produção.**

## Como executar

É necessário PHP com PDO SQLite habilitado.

Exemplo usando o servidor embutido do PHP, dentro da pasta `assistencia`:

```bash
php -S localhost:8000
```

Depois acesse `http://localhost:8000/login.php`.

## Observação

O sistema usa SQLite por padrão. O `schema.sql` também acompanha a estrutura completa do banco.

## Finanças (somente administrador)

Foi adicionada a aba **Finanças**, visível somente para administradores.

- Por padrão, a tela abre mostrando **somente o dia atual**.
- O administrador pode trocar o período pelo seletor **Dia / Semana / Mês / Ano**.
- O valor de **Ganho no período** é calculado pela soma dos pagamentos com status `Pago`.
- **Clientes atendidos** são os clientes únicos que tiveram uma OS marcada como `Entregue` no período selecionado.
- O gráfico muda automaticamente conforme o período escolhido:
  - Dia: recebimentos por hora;
  - Semana: recebimentos por dia;
  - Mês: recebimentos por dia do mês;
  - Ano: recebimentos por mês.
- Não são mostrados os quatro períodos simultaneamente: apenas o período selecionado fica em destaque.
