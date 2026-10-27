<?php

declare(strict_types=1);

namespace Rinha;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Acesso ao PostgreSQL.
 *
 * Cada worker do FrankenPHP mantém uma única conexão e statements preparados
 * durante toda a vida do processo. Isso elimina handshake TCP, autenticação e
 * parse/plan de SQL a cada request — que é onde implementações PHP "comuns"
 * perdem a maior parte do tempo.
 */
final class Repository
{
    /**
     * Quantas linhas acima da marca d'água a busca ainda consulta na tabela
     * principal. Cobre com folga o atraso normal do indexador (um lote de 500
     * mais a janela de 20 ms) e limita o custo do pior caso.
     */
    private const JANELA_BUSCA = 1000;

    private PDO $pdo;
    private PDOStatement $insert;
    private PDOStatement $get;
    private PDOStatement $search;
    private PDOStatement $count;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
    ) {
        $this->connectWithRetry();
    }

    private function connectWithRetry(): void
    {
        $attempts = 0;

        while (true) {
            try {
                $this->connect();
                return;
            } catch (PDOException $e) {
                if (++$attempts >= 120) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }

    private function connect(): void
    {
        $pdo = new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        // Evita que uma query travada prenda um worker para sempre.
        $pdo->exec("SET statement_timeout = '10s'");
        $pdo->exec("SET lock_timeout = '5s'");

        $this->pdo = $pdo;
        $this->insert = $pdo->prepare(
            'INSERT INTO pessoas (id, apelido, busca, resposta)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (apelido) DO NOTHING'
        );
        $this->get = $pdo->prepare('SELECT resposta FROM pessoas WHERE id = ?');
        // Duas origens, sem sobreposição:
        //  1. pessoas_busca  -> tudo até a marca d'água (índice GIN)
        //  2. pessoas        -> o que ainda não foi indexado (range scan pela
        //                       PK a partir da marca d'água)
        // Como o indexador grava o índice e avança a marca na mesma transação,
        // nenhuma linha aparece nas duas origens.
        //
        // O ramo 2 é limitado a JANELA linhas acima da marca de propósito. Sem
        // o teto ele vira realimentação positiva: se o indexador atrasa, cada
        // busca varre o atraso inteiro, o que consome a CPU que o indexador
        // precisava, o que aumenta o atraso. Medido: com 33k linhas e atraso de
        // 4k, cada busca custava ~2,4 ms e 100 buscas/s pediam ~0,24 core só
        // nesse ramo — contra uma cota de 0,89. O teto mantém o custo em ~0,5
        // ms por busca mesmo no pior caso; o excedente continua sendo indexado
        // e aparece na busca assim que a marca avança (consistência eventual).
        $this->search = $pdo->prepare(
            '(SELECT resposta FROM pessoas_busca WHERE busca LIKE ? LIMIT 50)
             UNION ALL
             (SELECT resposta FROM pessoas
              WHERE seq > (SELECT last_seq FROM estado_busca)
                AND seq <= (SELECT last_seq FROM estado_busca) + ' . self::JANELA_BUSCA . '
                AND busca LIKE ?
              LIMIT 50)
             LIMIT 50'
        );
        $this->count = $pdo->prepare('SELECT count(*) FROM pessoas');
    }

    private function reconnect(): void
    {
        unset($this->insert, $this->get, $this->search, $this->count, $this->pdo);
        $this->connectWithRetry();
    }

    /**
     * @return bool true se inseriu, false se o apelido já existia
     */
    public function insert(string $id, string $apelido, string $busca, string $resposta): bool
    {
        try {
            $this->insert->execute([$id, $apelido, $busca, $resposta]);
            return $this->insert->rowCount() === 1;
        } catch (PDOException $e) {
            if (!$this->isConnectionError($e)) {
                throw $e;
            }
            $this->reconnect();
            $this->insert->execute([$id, $apelido, $busca, $resposta]);
            return $this->insert->rowCount() === 1;
        }
    }

    public function get(string $id): string|false
    {
        try {
            $this->get->execute([$id]);
            return $this->get->fetchColumn();
        } catch (PDOException $e) {
            if (!$this->isConnectionError($e)) {
                throw $e;
            }
            $this->reconnect();
            $this->get->execute([$id]);
            return $this->get->fetchColumn();
        }
    }

    /**
     * @return list<string> JSONs já serializados
     */
    public function search(string $likePattern): array
    {
        try {
            $this->search->execute([$likePattern, $likePattern]);
            return $this->search->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (PDOException $e) {
            if (!$this->isConnectionError($e)) {
                throw $e;
            }
            $this->reconnect();
            $this->search->execute([$likePattern, $likePattern]);
            return $this->search->fetchAll(PDO::FETCH_COLUMN, 0);
        }
    }

    public function count(): string
    {
        try {
            $this->count->execute();
            return (string) $this->count->fetchColumn();
        } catch (PDOException $e) {
            if (!$this->isConnectionError($e)) {
                throw $e;
            }
            $this->reconnect();
            $this->count->execute();
            return (string) $this->count->fetchColumn();
        }
    }

    private function isConnectionError(PDOException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();
        $driverCode = $e->errorInfo[1] ?? 0;

        return $sqlState === 'HY000'
            || $driverCode === 7 // no connection to the server
            || $sqlState === '08000'
            || $sqlState === '08003'
            || $sqlState === '08006'
            || $sqlState === '08P01'
            || $sqlState === '57P01'
            || $sqlState === '57P02'
            || $sqlState === '57P03';
    }
}
