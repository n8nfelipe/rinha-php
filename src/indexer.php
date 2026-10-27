<?php

declare(strict_types=1);

/**
 * Indexador de busca.
 *
 * O POST grava a pessoa em `pessoas` (durável, sem índice GIN) e responde 201.
 * Este processo lê `pessoas` em ordem de `seq` a partir da marca d'água
 * `estado_busca.last_seq`, insere o lote em `pessoas_busca` (a única tabela com
 * índice GIN trigram) e avança a marca — tudo na MESMA transação.
 *
 * Por que existe: o GIN cobra caro por INSERT individual (update da pending
 * list). Uma linha por request derrubava a taxa de escrita para ~1/3. Em lote
 * de 500 o mesmo trabalho custa uma fração disso.
 *
 * O lote entra na pending list (fastupdate=on, barato) e em seguida é
 * derramado no índice com gin_clean_pending_list(). Medido: 45-90 ms por lote
 * de 500, contra 123 ms -> 1,3 s do modo fastupdate=off (que piora conforme as
 * posting lists crescem). O flush fica sempre com este processo, nunca com um
 * request, e mantém a pending list vazia para a busca.
 *
 * Por que não uma fila: uma tabela de fila exige DELETE a cada lote. O churn
 * gerava milhares de tuplas mortas e a busca (que precisava ler a fila para não
 * ter janela de inconsistência) varria esse lixo. Com marca d'água a tabela
 * principal é insert-only, e a busca lê o índice mais um range scan minúsculo
 * pela PK (`seq > last_seq`), que costuma ter poucas linhas.
 *
 * Atomicidade: índice e marca são commitados juntos. Logo, para qualquer
 * snapshot, ou a linha está em `pessoas_busca` e `seq <= last_seq`, ou está
 * acima da marca — nunca nas duas e nunca em nenhuma.
 */

const LOTE = 500;

function conectar(): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv('DB_HOST') ?: 'db',
        getenv('DB_PORT') ?: '5432',
        getenv('DB_NAME') ?: 'rinha',
    );

    $tentativas = 0;

    while (true) {
        try {
            $pdo = new PDO($dsn, getenv('DB_USER') ?: 'rinha', getenv('DB_PASS') ?: 'rinha', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $pdo->exec("SET statement_timeout = '120s'");
            $pdo->exec("SET lock_timeout = '30s'");

            return $pdo;
        } catch (PDOException $e) {
            if (++$tentativas >= 120) {
                throw $e;
            }
            fwrite(STDERR, '[indexer] aguardando banco: ' . $e->getMessage() . "\n");
            sleep(1);
        }
    }
}

function prepararLote(PDO $pdo): PDOStatement
{
    // Devolve quantas linhas entraram no lote para o loop decidir se dorme.
    // ON CONFLICT é só uma rede de segurança (o índice e a marca avançam
    // juntos, então uma linha nunca é processada duas vezes).
    return $pdo->prepare(
        'WITH proximo AS (
            SELECT seq, busca, resposta
            FROM pessoas
            WHERE seq > (SELECT last_seq FROM estado_busca)
            ORDER BY seq
            LIMIT ' . LOTE . '
         ), indexado AS (
            INSERT INTO pessoas_busca (seq, busca, resposta)
            SELECT seq, busca, resposta FROM proximo
            ON CONFLICT (seq) DO NOTHING
            RETURNING 1
         ), avanca AS (
            UPDATE estado_busca
            SET last_seq = COALESCE((SELECT max(seq) FROM proximo), last_seq)
            RETURNING 1
         )
         SELECT (SELECT count(*) FROM proximo)'
    );
}

$pdo = conectar();
$lote = prepararLote($pdo);
$flush = $pdo->prepare("SELECT gin_clean_pending_list('pessoas_busca_trgm')");

while (true) {
    try {
        $lote->execute();
        $consumidas = (int) ($lote->fetchColumn() ?: 0);
        $lote->closeCursor();

        if ($consumidas > 0) {
            // Derrama a pending list no índice. Roda DEPOIS do commit do lote:
            // as linhas já estão no índice (a pending list faz parte dele), e
            // assim o flush processa apenas tuplas visíveis, sem caso de borda
            // de MVCC.
            $flush->execute();
            $flush->closeCursor();
        }

        if ($consumidas >= LOTE) {
            // Ainda há atraso: continua sem dormir até alcançar o topo.
            continue;
        }

        // Alcançou o topo. 20ms de espera dá até 25k linhas/s de teto de
        // drenagem (muito acima dos ~600/s do teste) sem gastar CPU à toa.
        usleep(20_000);
    } catch (PDOException $e) {
        fwrite(STDERR, '[indexer] erro: ' . $e->getMessage() . "\n");

        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            // conexão já morta
        }

        sleep(1);
        $pdo = conectar();
        $lote = prepararLote($pdo);
        $flush = $pdo->prepare("SELECT gin_clean_pending_list('pessoas_busca_trgm')");
    }
}
