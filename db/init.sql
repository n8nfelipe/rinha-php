CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Tabela principal: TODOS os registros são gravados aqui de forma síncrona e
-- durável. Ela não tem índice de busca para não pagar o custo de GIN em cada
-- INSERT.
CREATE TABLE IF NOT EXISTS pessoas (
    seq      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    id       uuid         NOT NULL,
    apelido  varchar(32)  NOT NULL,
    busca    text         NOT NULL,
    resposta text         NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS pessoas_id_uq
    ON pessoas (id);

CREATE UNIQUE INDEX IF NOT EXISTS pessoas_apelido_uq
    ON pessoas (apelido);

-- Marca d'água do indexador: todos os registros com seq <= last_seq já estão
-- em pessoas_busca. Atualizada na MESMA transação que insere no índice, então
-- qualquer snapshot vê os dois consistentes.
--
-- Isso substitui uma fila: uma tabela de fila com DELETE em cada lote gerava
-- milhares de tuplas mortas, e a busca (que lia a fila) passava a varrer lixo.
CREATE TABLE IF NOT EXISTS estado_busca (
    last_seq bigint NOT NULL DEFAULT 0
);

INSERT INTO estado_busca (last_seq)
SELECT 0
WHERE NOT EXISTS (SELECT 1 FROM estado_busca);

-- Índice de busca derivado. É atualizado em lote pelo indexador, então o
-- caminho de escrita não paga o custo de GIN por request.
CREATE TABLE IF NOT EXISTS pessoas_busca (
    seq      bigint PRIMARY KEY,
    busca    text   NOT NULL,
    resposta text   NOT NULL
);

-- fastupdate=on, MAS o indexador força o flush a cada lote com
-- gin_clean_pending_list(). Medido com 33k linhas reais, lote de 500:
--
--   sem GIN (só heap + PK) ............   2-7 ms
--   fastupdate=off .................... 123 ms -> 1,3 s (cresce com o índice)
--   fastupdate=on + flush explícito ...  45-90 ms
--
-- fastupdate=off insere linha a linha no índice: a medida piora a cada lote
-- porque as posting lists dos trigramas crescem. A 600 inserts/s isso pede
-- ~1,5 core de CPU contra uma cota de 0,89 — o banco é throttled, o indexador
-- atrasa, a busca cai no ramo de fallback e varre a tabela principal. É uma
-- espiral: mais atraso -> mais CPU -> mais atraso.
--
-- Com fastupdate=on o lote é anexado à pending list (barato) e o flush em
-- bloco ordena e mescla de uma vez. O gin_pending_list_limit baixo é rede de
-- segurança: se o flush explícito falhar, a pending list nunca passa de 1MB,
-- então nenhuma busca varre mais que isso. (O parâmetro é em kB.)
CREATE INDEX IF NOT EXISTS pessoas_busca_trgm
    ON pessoas_busca USING gin (busca gin_trgm_ops)
    WITH (fastupdate = on, gin_pending_list_limit = 1024);

-- Ambas as tabelas são insert-only durante o teste: não há tuplas mortas para
-- recolher. Limites altos evitam que o autovacuum consuma CPU no meio do teste.
ALTER TABLE pessoas SET (
    autovacuum_vacuum_scale_factor = 0.5,
    autovacuum_vacuum_threshold = 50000,
    autovacuum_analyze_scale_factor = 0.5,
    autovacuum_analyze_threshold = 50000
);

ALTER TABLE pessoas_busca SET (
    autovacuum_vacuum_scale_factor = 0.5,
    autovacuum_vacuum_threshold = 50000,
    autovacuum_analyze_scale_factor = 0.5,
    autovacuum_analyze_threshold = 50000
);
