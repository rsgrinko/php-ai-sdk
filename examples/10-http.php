<?php

declare(strict_types=1);

/**
 * Пример 10. HTTP-запросы: методы, тело, заголовки, авторизация.
 *
 * Инструменты: http_request.
 * Флаги: allowPrivate (доступ во внутреннюю сеть), auth (креды в конструкторе
 *        по хосту и аргументом), method/query/body/headers/content_type.
 *
 * Сеть не нужна: показываем, что именно уйдёт на сервер, и защиту от SSRF.
 *
 * Запуск:
 *   php examples/10-http.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use AiSdk\HttpRequest;
use AiSdk\Tools\HttpRequestTool;

// 1. Сборка запроса — то же, что делает инструмент внутри.
$request = HttpRequest::build(
    method: 'POST',
    url: 'https://api.example.com/orders',
    query: ['page' => 2],
    body: ['sku' => 'A-1', 'qty' => 3],
    headers: ['X-Trace' => 'demo'],
    auth: ['user' => 'bot', 'pass' => 'secret'],
);

echo "1) запрос\n";
echo '   ' . $request->method . ' ' . $request->url . PHP_EOL;
echo '   body: ' . $request->body . PHP_EOL;

foreach ($request->headers as $name => $value) {
    echo '   ' . $name . ': ' . $value . PHP_EOL;
}

// 2. SSRF: локальный адрес запрещён по умолчанию.
echo PHP_EOL . "2) защита от SSRF\n";
echo '   ' . (new HttpRequestTool())->handle(['url' => 'http://localhost:8080/admin']) . PHP_EOL;
echo '   ' . (new HttpRequestTool())->handle(['url' => 'http://10.0.0.5/api']) . PHP_EOL;

// 3. Внутренняя сеть: снимается флагом allowPrivate, креды — по хосту.
//    Вызов раскомментируйте, если у вас по этому адресу действительно есть API.
$internal = new HttpRequestTool(
    auth: ['10.10.10.84' => ['user' => 'admin', 'pass' => '***']],
    allowPrivate: true,
);

echo PHP_EOL . "3) внутренний API\n";
echo '   инструмент создан с allowPrivate: true и кредами для 10.10.10.84' . PHP_EOL;
echo '   пример вызова: $internal->handle([\'url\' => \'http://10.10.10.84:8080/api/status\'])' . PHP_EOL;

// 4. Агент с этим инструментом — как в примере 02:
//
//    $tools = (new ToolRegistry())->add(new HttpRequestTool(allowPrivate: false));
//    $agent = new Agent(AiClient::fromEnv(), $tools);
//    echo $agent->ask('Сходи на https://api.example.com/status и перескажи ответ');
