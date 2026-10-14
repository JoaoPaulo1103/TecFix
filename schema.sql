-- Schema da Assistência Técnica
-- Compatível com SQLite (usado pelo config.php).
-- Para MySQL: troque AUTOINCREMENT por AUTO_INCREMENT, TEXT por VARCHAR
-- onde fizer sentido, e datetime('now') pelas funções equivalentes.

CREATE TABLE usuarios (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nome        TEXT NOT NULL,
    email       TEXT NOT NULL UNIQUE,
    senha_hash  TEXT NOT NULL,
    cargo       TEXT NOT NULL CHECK (cargo IN ('admin','tecnico','atendente')),
    ativo       INTEGER NOT NULL DEFAULT 1,
    imagem      TEXT,
    criado_em   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE clientes (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nome        TEXT NOT NULL,
    telefone    TEXT NOT NULL,
    email       TEXT,
    cpf         TEXT UNIQUE,
    endereco    TEXT,
    criado_em   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE aparelhos (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    cliente_id          INTEGER NOT NULL REFERENCES clientes(id) ON DELETE RESTRICT,
    tipo                TEXT NOT NULL,        -- Smartphone, Tablet, Notebook...
    marca               TEXT NOT NULL,
    modelo              TEXT NOT NULL,
    numero_serie        TEXT,
    cor                 TEXT,
    senha_desbloqueio   TEXT,
    acessorios          TEXT,
    criado_em           TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE ordens_servico (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo              TEXT NOT NULL UNIQUE,     -- ex: OS-2026-0001
    aparelho_id         INTEGER NOT NULL REFERENCES aparelhos(id) ON DELETE RESTRICT,
    usuario_id          INTEGER REFERENCES usuarios(id),  -- responsável atual
    status              TEXT NOT NULL DEFAULT 'Recebido'
                        CHECK (status IN ('Recebido','Em análise','Aguardando aprovação',
                                          'Em conserto','Pronto','Entregue','Cancelado')),
    defeito_relatado    TEXT NOT NULL,
    laudo_tecnico       TEXT,
    valor_orcamento     REAL,
    orcamento_aprovado  INTEGER NOT NULL DEFAULT 0,
    data_abertura       TEXT NOT NULL DEFAULT (datetime('now')),
    data_previsao       TEXT,
    data_conclusao      TEXT,
    data_entrega        TEXT,
    criado_em           TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE servicos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ordem_id    INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
    descricao   TEXT NOT NULL,
    tipo        TEXT NOT NULL CHECK (tipo IN ('peca','mao_de_obra')),
    valor       REAL NOT NULL,
    criado_em   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE pagamentos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ordem_id        INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
    valor           REAL NOT NULL,
    forma_pagamento TEXT NOT NULL CHECK (forma_pagamento IN ('Pix','Dinheiro','Cartão de Crédito','Cartão de Débito')),
    status          TEXT NOT NULL DEFAULT 'Pago' CHECK (status IN ('Pendente','Pago','Estornado')),
    data_pagamento  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Tabela extra (não pedida, mas essencial para regra de negócio de auditoria):
-- guarda cada mudança de status da OS, com quem fez e quando.
CREATE TABLE ordens_status_historico (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ordem_id    INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
    status      TEXT NOT NULL,
    usuario_id  INTEGER REFERENCES usuarios(id),
    observacao  TEXT,
    criado_em   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_aparelhos_cliente ON aparelhos(cliente_id);
CREATE INDEX idx_os_aparelho ON ordens_servico(aparelho_id);
CREATE INDEX idx_os_status ON ordens_servico(status);
CREATE INDEX idx_servicos_ordem ON servicos(ordem_id);
CREATE INDEX idx_pagamentos_ordem ON pagamentos(ordem_id);
CREATE INDEX idx_historico_ordem ON ordens_status_historico(ordem_id);


-- Imagens anexadas à OS. Permitidas somente nos status Recebido, Em análise e Pronto.
CREATE TABLE os_imagens (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ordem_id        INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
    usuario_id      INTEGER REFERENCES usuarios(id),
    status_os       TEXT NOT NULL,
    nome_original   TEXT NOT NULL,
    arquivo         TEXT NOT NULL UNIQUE,
    mime            TEXT NOT NULL,
    tamanho         INTEGER NOT NULL,
    criado_em       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_os_imagens_ordem ON os_imagens(ordem_id);


-- Catálogo da loja e produtos adicionados ao orçamento da OS.
CREATE TABLE IF NOT EXISTS produtos (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    nome        TEXT NOT NULL,
    descricao   TEXT,
    preco       REAL NOT NULL,
    estoque     INTEGER NOT NULL DEFAULT 0,
    ativo       INTEGER NOT NULL DEFAULT 1,
    imagem      TEXT,
    criado_em   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS os_produtos (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ordem_id        INTEGER NOT NULL REFERENCES ordens_servico(id) ON DELETE CASCADE,
    produto_id      INTEGER NOT NULL REFERENCES produtos(id) ON DELETE RESTRICT,
    quantidade      INTEGER NOT NULL DEFAULT 1 CHECK (quantidade > 0),
    valor_unitario  REAL NOT NULL,
    criado_em       TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_produtos_ativo ON produtos(ativo);
CREATE INDEX IF NOT EXISTS idx_os_produtos_ordem ON os_produtos(ordem_id);
