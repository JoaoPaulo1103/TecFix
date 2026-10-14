# Mercado Pago Pix — TecFix

O projeto está preparado para gerar cobranças Pix reais pelo Mercado Pago.

## Configuração

1. Faça uma cópia de `config.local.example.php` e renomeie para `config.local.php`.
2. Coloque no arquivo o **Access Token** do Mercado Pago.
3. Não publique `config.local.php` nem compartilhe o token.
4. O servidor PHP precisa ter a extensão **cURL** habilitada.
5. Para produção, hospede o sistema em **HTTPS**.

## Fluxo

- O cliente adiciona produtos ao carrinho.
- Clica em pagar e escolhe Pix.
- `pix_criar.php` cria o pagamento no endpoint `/v1/payments`.
- O Mercado Pago devolve `qr_code` e `qr_code_base64`.
- O site mostra QR Code + Pix Copia e Cola.
- `pix_status.php` consulta o status enquanto a tela está aberta.
- `pix_webhook.php` recebe atualizações quando o endpoint público HTTPS estiver configurado.
- O pagamento só entra no total pago da OS quando o status for `Pago`.

## Webhook

Quando o site estiver hospedado em HTTPS, defina em `config.local.php`:

`MP_NOTIFICATION_URL=https://SEU-DOMINIO/pix_webhook.php`

Depois configure as notificações de pagamento na aplicação do Mercado Pago.

## Segurança

O Access Token fica exclusivamente no PHP. Não coloque a credencial em HTML ou JavaScript.
