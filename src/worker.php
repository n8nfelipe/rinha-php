<?php

declare(strict_types=1);

/**
 * Worker do FrankenPHP.
 *
 * O processo/thread sobe uma vez, abre a conexão com o Postgres e prepara os
 * statements. O loop abaixo entrega cada request ao mesmo estado já aquecido.
 */

require __DIR__ . '/Repository.php';
require __DIR__ . '/App.php';

use Rinha\App;

$app = new App();

$handler = static function () use ($app): void {
    try {
        $app->handle();
    } catch (Throwable $e) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo '{"erro":"erro interno"}';
        }
        error_log('[rinha] ' . $e->getMessage());
    }
};

$requests = 0;

while (frankenphp_handle_request($handler)) {
    // Coleta ciclos raramente: gc_collect_cycles() a cada request custa caro
    // e a aplicação praticamente não cria estruturas cíclicas.
    if ((++$requests & 1023) === 0) {
        gc_collect_cycles();
    }
}
