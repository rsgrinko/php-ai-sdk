<?php

declare(strict_types=1);

// Тесты: files. Запускаются из tests/run.php.

use AiSdk\Tools\CsvQueryTool;
use AiSdk\Tools\FileSandbox;
use AiSdk\Tools\FindFilesTool;
use AiSdk\Tools\ListDirTool;
use AiSdk\Tools\ReadFileTool;
use AiSdk\Tools\ReplaceInFileTool;
use AiSdk\Tools\SearchFilesTool;
use AiSdk\Tools\WriteFileTool;

// ---------------------------------------------------------------------------
// Файлы
// ---------------------------------------------------------------------------

test('read_file: читает файл внутри корня', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/note.txt', "первая\nвторая\nтретья");

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => 'note.txt']);

    assertContains('строки 1–3 из 3', $result);
    assertContains('вторая', $result);
});

test('read_file: offset и limit в строках', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/note.txt', "1\n2\n3\n4\n5");

    $result = (new ReadFileTool(new FileSandbox($dir)))
        ->handle(['path' => 'note.txt', 'offset' => 1, 'limit' => 2]);

    assertContains('строки 2–3 из 5', $result);
    assertContains("2\n3", $result);
});

test('read_file: побег через .. запрещён', function (): void {
    $dir = testDir();
    file_put_contents(dirname($dir) . '/outside.txt', 'секрет');

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => '../outside.txt']);

    assertContains('не должен содержать', $result);
});

test('read_file: абсолютный путь запрещён', function (): void {
    $dir = testDir();

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => '/etc/passwd']);

    assertContains('Абсолютные пути запрещены', $result);
});

test('read_file: бинарный файл не читается', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/bin.dat', "\x00\x01\x02\xFF");

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => 'bin.dat']);

    assertContains('бинарный', $result);
});

test('list_dir: показывает каталоги и файлы', function (): void {
    $dir = testDir();
    mkdir($dir . '/sub');
    file_put_contents($dir . '/a.txt', 'hello');

    $result = (new ListDirTool(new FileSandbox($dir)))->handle([]);

    assertContains('sub/', $result);
    assertContains('a.txt', $result);
});

test('list_dir: выход за корень запрещён', function (): void {
    $dir = testDir();

    $result = (new ListDirTool(new FileSandbox($dir)))->handle(['path' => '..']);

    assertContains('не должен содержать', $result);
});

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

test('csv_query: фильтр по колонке', function (): void {
    $dir = testDir();
    file_put_contents(
        $dir . '/people.csv',
        "name,city,age\nИван,Тула,30\nМария,Москва,25\nПётр,Тула,41\n"
    );

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle([
        'path' => 'people.csv',
        'where' => 'city=Тула',
    ]);

    assertContains('Иван', $result);
    assertContains('Пётр', $result);
    assertTrue(!str_contains($result, 'Мария'), 'строка из другого города попала в выборку');
    assertContains('2 из 3 строк', $result);
});

test('csv_query: вхождение подстроки и лимит', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/people.csv', "name,city\nИван,Тула\nИгорь,Тула\nПётр,Тула\n");

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle([
        'path' => 'people.csv',
        'where' => 'name~и',
        'columns' => 'name',
        'limit' => 2,
    ]);

    assertContains('2 из 3 строк', $result);
    assertTrue(!str_contains($result, 'city'), 'лишняя колонка в выборке');
});

test('csv_query: неизвестная колонка — понятная ошибка', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/people.csv', "name,city\nИван,Тула\n");

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle([
        'path' => 'people.csv',
        'where' => 'город=Тула',
    ]);

    assertContains('Нет колонки', $result);
    assertContains('name, city', $result);
});

test('csv_query: ошибки ввода — текстом, а не исключением', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/people.csv', "name,city\nИван,Тула\n");
    file_put_contents($dir . '/empty.csv', '');
    $tool = new CsvQueryTool(new FileSandbox($dir));

    assertContains('Фильтр должен быть', $tool->handle(['path' => 'people.csv', 'where' => 'city']));
    assertContains('Нет колонки "age"', $tool->handle(['path' => 'people.csv', 'columns' => 'name, age']));
    assertContains('заголовок', $tool->handle(['path' => 'empty.csv']));
    assertContains('Путь не найден', $tool->handle(['path' => 'nope.csv']));
});

test('csv_query: лимит считает строки без заголовка', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/n.csv', "n\n1\n2\n3\n4\n");

    $result = (new CsvQueryTool(new FileSandbox($dir)))->handle(['path' => 'n.csv', 'limit' => 2]);

    assertContains('2 из 4 строк', $result);
    assertTrue(!str_contains($result, '3'), 'лишняя строка: ' . $result);
});

// ---------------------------------------------------------------------------
// Запись и правка файлов
// ---------------------------------------------------------------------------

test('write_file: создаёт файл', function (): void {
    $dir = testDir();
    $result = (new WriteFileTool(new FileSandbox($dir)))->handle([
        'path' => 'note.txt',
        'content' => 'привет',
    ]);

    assertContains('Создан', $result);
    assertSame('привет', file_get_contents($dir . '/note.txt'));
});

test('write_file: перезаписывает файл', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/note.txt', 'старое');

    $result = (new WriteFileTool(new FileSandbox($dir)))->handle([
        'path' => 'note.txt',
        'content' => 'новое',
    ]);

    assertContains('Перезаписан', $result);
    assertSame('новое', file_get_contents($dir . '/note.txt'));
});

test('write_file: создаёт каталоги с create_dirs', function (): void {
    $dir = testDir();

    $result = (new WriteFileTool(new FileSandbox($dir)))->handle([
        'path' => 'a/b/c.txt',
        'content' => 'x',
        'create_dirs' => true,
    ]);

    assertContains('Создан', $result);
    assertTrue(is_file($dir . '/a/b/c.txt'), 'файл не создан');
});

test('write_file: без create_dirs каталог обязателен', function (): void {
    $dir = testDir();

    $result = (new WriteFileTool(new FileSandbox($dir)))->handle([
        'path' => 'nope/x.txt',
        'content' => 'x',
    ]);

    assertContains('Каталог не существует', $result);
});

test('write_file: запись вне песочницы запрещена', function (): void {
    $dir = testDir();

    $result = (new WriteFileTool(new FileSandbox($dir)))->handle([
        'path' => '../evil.txt',
        'content' => 'x',
    ]);

    assertContains('не должен содержать', $result);
});

test('replace_in_file: заменяет единственное совпадение', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/code.txt', "class A\nclass B\n");

    $result = (new ReplaceInFileTool(new FileSandbox($dir)))->handle([
        'path' => 'code.txt',
        'search' => 'class A',
        'replace' => 'final class A',
    ]);

    assertContains('Заменено вхождений: 1', $result);

    $content = (string) file_get_contents($dir . '/code.txt');

    assertContains('final class A', $content);
    assertContains('class B', $content);
});

test('replace_in_file: несколько совпадений требуют all', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/code.txt', "x\nx\n");

    $result = (new ReplaceInFileTool(new FileSandbox($dir)))->handle([
        'path' => 'code.txt',
        'search' => 'x',
        'replace' => 'y',
    ]);

    assertContains('Найдено совпадений: 2', $result);
});

test('replace_in_file: all заменяет все и сообщает сколько', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/code.txt', "x\nx\nx\n");

    $result = (new ReplaceInFileTool(new FileSandbox($dir)))->handle([
        'path' => 'code.txt',
        'search' => 'x',
        'replace' => 'y',
        'all' => true,
    ]);

    assertContains('Заменено вхождений: 3', $result);
    assertSame("y\ny\ny\n", file_get_contents($dir . '/code.txt'));
});

test('replace_in_file: не найдено — понятная ошибка', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/code.txt', 'abc');

    $result = (new ReplaceInFileTool(new FileSandbox($dir)))->handle([
        'path' => 'code.txt',
        'search' => 'zzz',
        'replace' => 'y',
    ]);

    assertContains('не найден', $result);
});

// ---------------------------------------------------------------------------
// Поиск по файлам
// ---------------------------------------------------------------------------

test('search_files: находит строки с номерами', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/a.php', "<?php\nclass Foo {}\n");
    file_put_contents($dir . '/b.txt', "class Bar\n");

    $result = (new SearchFilesTool(new FileSandbox($dir)))->handle(['pattern' => 'class \w+']);

    assertContains('a.php:2:', $result);
    assertContains('b.txt:1:', $result);
});

test('search_files: glob ограничивает файлы', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/a.php', "class Foo\n");
    file_put_contents($dir . '/b.txt', "class Bar\n");

    $result = (new SearchFilesTool(new FileSandbox($dir)))->handle([
        'pattern' => 'class',
        'glob' => '*.php',
    ]);

    assertContains('a.php', $result);
    assertTrue(!str_contains($result, 'b.txt'), 'лишний файл в выдаче');
});

test('search_files: служебные каталоги пропускаются', function (): void {
    $dir = testDir();
    mkdir($dir . '/vendor', 0777, true);
    file_put_contents($dir . '/vendor/lib.php', "class Vendor\n");
    file_put_contents($dir . '/app.php', "class App\n");

    $result = (new SearchFilesTool(new FileSandbox($dir)))->handle(['pattern' => 'class']);

    assertContains('app.php', $result);
    assertTrue(!str_contains($result, 'vendor'), 'vendor не должен сканироваться');
});

test('search_files: некорректная регулярка', function (): void {
    $dir = testDir();

    $result = (new SearchFilesTool(new FileSandbox($dir)))->handle(['pattern' => '([']);

    assertContains('Некорректное регулярное выражение', $result);
});

test('find_files: поиск по маске', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/a.php', 'x');
    file_put_contents($dir . '/b.txt', 'x');

    $result = (new FindFilesTool(new FileSandbox($dir)))->handle(['pattern' => '*.php']);

    assertContains('a.php', $result);
    assertTrue(!str_contains($result, 'b.txt'), 'лишний файл в выдаче');
});

// ---------------------------------------------------------------------------
// Песочница: ограниченный и свободный режимы
// ---------------------------------------------------------------------------

test('sandbox: по умолчанию ограничена', function (): void {
    $dir = testDir();

    assertTrue((new FileSandbox($dir))->isRestricted());
    assertTrue(!FileSandbox::unrestricted($dir)->isRestricted());
});

test('sandbox: свободный режим читает вне корня', function (): void {
    $dir = testDir();
    $outside = str_replace('\\', '/', dirname($dir) . '/outside.txt');
    file_put_contents($outside, 'снаружи');

    $result = (new ReadFileTool(FileSandbox::unrestricted($dir)))->handle(['path' => $outside]);

    assertContains('снаружи', $result);
});

test('sandbox: свободный режим пишет вне корня', function (): void {
    $dir = testDir();
    $outside = str_replace('\\', '/', dirname($dir) . '/written.txt');

    $result = (new WriteFileTool(FileSandbox::unrestricted($dir)))->handle([
        'path' => $outside,
        'content' => 'записано',
    ]);

    assertContains('Создан', $result);
    assertSame('записано', file_get_contents($outside));
});

test('sandbox: политика задаётся для каждого инструмента отдельно', function (): void {
    $dir = testDir();
    $outside = str_replace('\\', '/', dirname($dir) . '/mixed.txt');
    file_put_contents($outside, 'данные');

    // Читать можно везде...
    $read = new ReadFileTool(FileSandbox::unrestricted($dir));
    assertContains('данные', $read->handle(['path' => $outside]));

    // ...а писать — только в корень.
    $write = new WriteFileTool(new FileSandbox($dir));
    assertContains('запрещены', $write->handle(['path' => $outside, 'content' => 'x']));
});

test('sandbox: ограниченный режим по-прежнему блокирует выход', function (): void {
    $dir = testDir();
    $outside = dirname($dir) . '/blocked.txt';
    file_put_contents($outside, 'секрет');

    $result = (new ReadFileTool(new FileSandbox($dir)))->handle(['path' => '../blocked.txt']);

    assertContains('не должен содержать', $result);
});



test('search_files: лимит останавливает обход', function (): void {
    $dir = testDir();
    file_put_contents($dir . '/a.txt', "one\ntwo\nthree\n");

    $result = (new SearchFilesTool(new FileSandbox($dir)))->handle([
        'pattern' => '\w+',
        'limit' => 2,
    ]);

    assertContains('обрезано по лимиту 2', $result);
    assertContains('Найдено 2', $result);
});

test('find_files: лимит останавливает обход', function (): void {
    $dir = testDir();

    for ($i = 1; $i <= 5; $i++) {
        file_put_contents($dir . '/f' . $i . '.php', 'x');
    }

    $result = (new FindFilesTool(new FileSandbox($dir)))->handle([
        'pattern' => '*.php',
        'limit' => 2,
    ]);

    assertContains('обрезано по лимиту 2', $result);
    assertContains('Найдено 2', $result);
});
