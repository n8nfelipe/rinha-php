<?php

declare(strict_types=1);

namespace Rinha;

use stdClass;

/**
 * Aplicação da Rinha de Backend 2023 Q3.
 *
 * Roda dentro do worker mode do FrankenPHP: o bootstrap (autoload, conexão
 * PDO, statements preparados) acontece uma vez por thread e é reaproveitado
 * por todos os requests.
 */
final class App
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
    private const DATE_RE = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    private readonly Repository $repository;

    public function __construct()
    {
        $this->repository = new Repository(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: 'db',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'rinha',
            ),
            getenv('DB_USER') ?: 'rinha',
            getenv('DB_PASS') ?: 'rinha',
        );
    }

    public function handle(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $queryPos = strpos($uri, '?');
        $path = $queryPos === false ? $uri : substr($uri, 0, $queryPos);

        if ($method === 'POST') {
            if ($path === '/pessoas') {
                $this->createPessoa();
                return;
            }
            $this->notFound();
            return;
        }

        if ($method !== 'GET') {
            $this->notFound();
            return;
        }

        if ($path === '/pessoas') {
            $this->searchPessoas();
            return;
        }

        if ($path === '/contagem-pessoas') {
            $this->contagemPessoas();
            return;
        }

        if ($path === '/__debug' && getenv('APP_DEBUG') === '1') {
            $this->debug();
            return;
        }

        if (str_starts_with($path, '/pessoas/')) {
            $this->showPessoa(substr($path, 9));
            return;
        }

        $this->notFound();
    }

    private function createPessoa(): void
    {
        $raw = file_get_contents('php://input');

        if ($raw === false || $raw === '' || strlen($raw) > 1_000_000) {
            $this->badRequest('corpo inválido');
            return;
        }

        $data = json_decode($raw);

        if (!$data instanceof stdClass) {
            $this->badRequest('json inválido');
            return;
        }

        $apelido = $data->apelido ?? null;
        if ($apelido === null) {
            $this->unprocessable('apelido é obrigatório');
            return;
        }
        if (!is_string($apelido)) {
            $this->badRequest('apelido deve ser string');
            return;
        }
        if (str_contains($apelido, "\0")) {
            $this->unprocessable('apelido inválido');
            return;
        }
        $apelidoLen = mb_strlen($apelido, 'UTF-8');
        if ($apelidoLen === 0 || $apelidoLen > 32) {
            $this->unprocessable('apelido deve ter de 1 a 32 caracteres');
            return;
        }

        $nome = $data->nome ?? null;
        if ($nome === null) {
            $this->unprocessable('nome é obrigatório');
            return;
        }
        if (!is_string($nome)) {
            $this->badRequest('nome deve ser string');
            return;
        }
        if (str_contains($nome, "\0")) {
            $this->unprocessable('nome inválido');
            return;
        }
        $nomeLen = mb_strlen($nome, 'UTF-8');
        if ($nomeLen === 0 || $nomeLen > 100) {
            $this->unprocessable('nome deve ter de 1 a 100 caracteres');
            return;
        }

        $nascimento = $data->nascimento ?? null;
        if ($nascimento === null) {
            $this->unprocessable('nascimento é obrigatório');
            return;
        }
        if (!is_string($nascimento)) {
            $this->badRequest('nascimento deve ser string');
            return;
        }
        if (
            preg_match(self::DATE_RE, $nascimento, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
        ) {
            $this->unprocessable('nascimento deve ser uma data AAAA-MM-DD válida');
            return;
        }

        $stack = $data->stack ?? null;
        $stackJson = 'null';

        if ($stack !== null) {
            if (!is_array($stack)) {
                $this->badRequest('stack deve ser um array de strings');
                return;
            }

            foreach ($stack as $item) {
                if (!is_string($item)) {
                    $this->badRequest('stack deve ser um array de strings');
                    return;
                }
                if (str_contains($item, "\0")) {
                    $this->unprocessable('stack inválida');
                    return;
                }
                $itemLen = mb_strlen($item, 'UTF-8');
                if ($itemLen === 0 || $itemLen > 32) {
                    $this->unprocessable('cada item de stack deve ter de 1 a 32 caracteres');
                    return;
                }
            }

            $stackJson = json_encode($stack, self::JSON_FLAGS);
            if ($stackJson === false) {
                $this->badRequest('stack inválida');
                return;
            }
        }

        $id = self::uuid7();

        // "busca" é o texto já normalizado que o índice GIN/trigram usa. Fazer
        // isso na escrita deixa a busca apenas um LIKE indexado.
        //
        // Os campos são separados por \x01 (SOH) e não por espaço: a regra diz
        // que o termo precisa estar contido em apelido, nome OU em um elemento
        // de stack. Com espaço, um termo como "a b" casaria atravessando a
        // fronteira entre dois campos — falso positivo. Como \x01 nunca aparece
        // no termo (é removido na busca), o casamento fica restrito a um campo.
        $busca = mb_strtolower(
            $apelido . "\x01" . $nome
            . ($stack !== null && $stack !== [] ? "\x01" . implode("\x01", $stack) : ''),
            'UTF-8'
        );

        // Resposta de GET já serializada no momento da escrita. GET e busca
        // devolvem esse texto direto para o socket, sem json_encode por request.
        $resposta = '{"id":"' . $id
            . '","apelido":' . json_encode($apelido, self::JSON_FLAGS)
            . ',"nome":' . json_encode($nome, self::JSON_FLAGS)
            . ',"nascimento":"' . $nascimento
            . '","stack":' . $stackJson
            . '}';

        if (!$this->repository->insert($id, $apelido, $busca, $resposta)) {
            $this->unprocessable('apelido já existe');
            return;
        }

        http_response_code(201);
        header('Location: /pessoas/' . $id);
        header('Content-Length: 0');
    }

    private function showPessoa(string $id): void
    {
        if (preg_match(self::UUID_RE, $id) !== 1) {
            $this->notFound();
            return;
        }

        $resposta = $this->repository->get($id);

        if ($resposta === false) {
            $this->notFound();
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($resposta));
        echo $resposta;
    }

    private function searchPessoas(): void
    {
        $termo = $_GET['t'] ?? null;

        if (!is_string($termo)) {
            $this->badRequest('parâmetro t é obrigatório');
            return;
        }

        if (str_contains($termo, "\0")) {
            $this->badRequest('parâmetro t inválido');
            return;
        }

        // \x01 é o separador de campos em "busca". Se viesse no termo, ele
        // poderia casar atravessando dois campos; trocamos por espaço para
        // manter o casamento sempre dentro de um único atributo.
        $termo = str_replace("\x01", ' ', $termo);

        // LIKE com escape dos curingas. A coluna já está em minúsculas, então
        // não precisamos de ILIKE (mais caro) nem de lower() na query.
        $pattern = '%' . str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            mb_strtolower($termo, 'UTF-8')
        ) . '%';

        $rows = $this->repository->search($pattern);
        $body = '[' . implode(',', $rows) . ']';

        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private function contagemPessoas(): void
    {
        $count = $this->repository->count();

        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: ' . strlen($count));
        echo $count;
    }

    private function debug(): void
    {
        $status = opcache_get_status(false);
        $body = json_encode([
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'jit' => $status['jit'] ?? null,
            'opcache_enabled' => $status['opcache_enabled'] ?? null,
            'memory' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
        ], self::JSON_FLAGS);

        http_response_code(200);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    private function badRequest(string $message): void
    {
        $this->jsonError(400, $message);
    }

    private function unprocessable(string $message): void
    {
        $this->jsonError(422, $message);
    }

    private function notFound(): void
    {
        $this->jsonError(404, 'não encontrado');
    }

    private function jsonError(int $status, string $message): void
    {
        $body = '{"erro":' . json_encode($message, self::JSON_FLAGS) . '}';

        http_response_code($status);
        header('Content-Type: application/json');
        header('Content-Length: ' . strlen($body));
        echo $body;
    }

    /**
     * UUID v7: ordenado pelo tempo, o que preserva a localidade da PK no
     * B-tree do Postgres (UUID v4 aleatório fragmenta o índice).
     */
    private static function uuid7(): string
    {
        $bytes = random_bytes(16);
        $ms = (int) floor(microtime(true) * 1000);

        $bytes[0] = chr(($ms >> 40) & 0xFF);
        $bytes[1] = chr(($ms >> 32) & 0xFF);
        $bytes[2] = chr(($ms >> 24) & 0xFF);
        $bytes[3] = chr(($ms >> 16) & 0xFF);
        $bytes[4] = chr(($ms >> 8) & 0xFF);
        $bytes[5] = chr($ms & 0xFF);
        $bytes[6] = chr(0x70 | (ord($bytes[6]) & 0x0F));
        $bytes[8] = chr(0x80 | (ord($bytes[8]) & 0x3F));

        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-'
            . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-'
            . substr($hex, 20, 12);
    }
}
