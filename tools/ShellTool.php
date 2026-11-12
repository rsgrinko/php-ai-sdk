<?php

declare(strict_types=1);

namespace AiSdk\Tools;

use AiSdk\Tool;
use AiSdk\Tools\Support\Timeout;

/**
 * Выполнение команд оболочки.
 *
 * ИНСТРУМЕНТ ОПАСЕН: он даёт модели запускать произвольные команды от имени
 * процесса. Он не добавляет себя в реестр сам — вы регистрируете его осознанно.
 *
 * Предохранители, которые не мешают обычной работе:
 *   - stdin закрыт: интерактивные команды (vim, more, ssh) не зависнут;
 *   - таймаут с убийством процесса;
 *   - ограничение вывода, чтобы не раздувать контекст;
 *   - необязательный allowlist по первой команде.
 *
 * Чего здесь НЕТ: изоляции, песочницы, ограничения файловой системы.
 * Команда может делать всё, что может пользователь процесса.
 */
final class ShellTool implements Tool
{
    private readonly ?string $workdir;
    private readonly string $shellName;
    private readonly string $tmpDir;

    /**
     * @param string|null $workdir каталог, где выполняется команда (по умолчанию текущий)
     * @param string $shell auto | bash | sh | cmd | powershell | pwsh
     * @param float $timeout секунды до принудительного завершения (по умолчанию для модели)
     * @param float $maxTimeout предел, который модель не может превысить
     * @param int $maxOutput предел вывода stdout/stderr в байтах
     * @param list<string> $allow если не пусто — разрешены только эти первые слова
     * @param string|null $tmpDir где создавать файлы вывода (по умолчанию системный temp)
     */
    public function __construct(
        ?string $workdir = null,
        string $shell = 'auto',
        private readonly float $timeout = 10.0,
        private readonly float $maxTimeout = 120.0,
        private readonly int $maxOutput = 16000,
        private readonly array $allow = [],
        ?string $tmpDir = null,
    ) {
        $this->workdir = $workdir !== null && $workdir !== '' ? $workdir : null;
        $this->shellName = strtolower(trim($shell));
        $this->tmpDir = $tmpDir !== null && $tmpDir !== '' ? $tmpDir : sys_get_temp_dir();
    }

    public function name(): string
    {
        return 'run_command';
    }

    public function description(): string
    {
        return 'Выполнить команду оболочки и вернуть код выхода, stdout и stderr. '
            . 'Каждый вызов — новый процесс: cd и переменные не сохраняются между вызовами. '
            . 'Stdin закрыт, поэтому интерактивные команды не работают. '
            . 'Есть таймаут и ограничение вывода.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Команда, например "ls -la" или "php -v".',
                ],
                'timeout' => [
                    'type' => 'number',
                    'description' => Timeout::parameterDescription($this->timeout, $this->maxTimeout),
                ],
            ],
            'required' => ['command'],
            'additionalProperties' => false,
        ];
    }

    public function handle(array $args): string
    {
        $command = trim((string) ($args['command'] ?? ''));

        if ($command === '') {
            return 'Пустая команда.';
        }

        if (!$this->isAllowed($command)) {
            return 'Команда не разрешена: "' . $this->firstWord($command) . '". '
                . 'Разрешены: ' . implode(', ', $this->allow) . '.';
        }

        $timeout = Timeout::from($args, $this->timeout, $this->maxTimeout);

        try {
            $commandLine = $this->commandLine($command);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $cwd = $this->workdir ?? getcwd();

        if ($cwd === false || !is_dir($cwd)) {
            return 'Рабочий каталог недоступен: ' . (string) ($this->workdir ?? '');
        }

        $files = $this->tempFiles();

        if ($files === null) {
            return 'Не удалось создать временные файлы для вывода в ' . $this->tmpDir . '.';
        }

        [$outFile, $errFile] = $files;

        try {
            $run = $this->run($commandLine, $cwd, $outFile, $errFile, $timeout->seconds);
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        } finally {
            $stdout = (string) @file_get_contents($outFile);
            $stderr = (string) @file_get_contents($errFile);
            @unlink($outFile);
            @unlink($errFile);
        }

        return $this->format($command, $stdout, $stderr, $run['timedOut'], $run['overflow'], $run['exitCode'], $timeout->seconds);
    }

    /**
     * Вывод пишем в файлы, а не в пайпы: на Windows неблокирующее чтение
     * пайпов не работает, и цикл ожидания съедал весь таймаут.
     *
     * @return array{0: string, 1: string}|null пути stdout и stderr
     */
    private function tempFiles(): ?array
    {
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0777, true) && !is_dir($this->tmpDir)) {
            return null;
        }

        $out = @tempnam($this->tmpDir, 'aisdk_out_');
        $err = @tempnam($this->tmpDir, 'aisdk_err_');

        if ($out === false || $err === false) {
            if ($out !== false) {
                @unlink($out);
            }

            if ($err !== false) {
                @unlink($err);
            }

            return null;
        }

        return [$out, $err];
    }

    /**
     * Запустить процесс и дождаться завершения.
     *
     * @return array{timedOut: bool, overflow: bool, exitCode: int}
     */
    private function run(string $commandLine, string $cwd, string $outFile, string $errFile, float $timeout): array
    {
        $process = @proc_open($commandLine, [
            0 => ['pipe', 'r'],
            1 => ['file', $outFile, 'w'],
            2 => ['file', $errFile, 'w'],
        ], $pipes, $cwd);

        if (!is_resource($process)) {
            throw new \RuntimeException('Не удалось запустить команду.');
        }

        // Stdin сразу закрываем: иначе ждущая ввода команда повиснет.
        @fclose($pipes[0]);

        $timedOut = false;
        $overflow = false;
        $exitCode = null;
        $startedAt = microtime(true);
        $diskLimit = $this->maxOutput * 4;

        while (true) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if ((microtime(true) - $startedAt) > $timeout) {
                $timedOut = true;
                @proc_terminate($process, 9);
                $this->killTree((int) $status['pid']);
                break;
            }

            // Команда пишет без остановки — не даём забить диск.
            $written = (@filesize($outFile) ?: 0) + (@filesize($errFile) ?: 0);

            if ($written > $diskLimit) {
                $overflow = true;
                @proc_terminate($process, 9);
                $this->killTree((int) $status['pid']);
                break;
            }

            usleep(10_000);
        }

        $closeCode = @proc_close($process);

        if ($exitCode === null || $exitCode < 0) {
            $exitCode = $closeCode;
        }

        return ['timedOut' => $timedOut, 'overflow' => $overflow, 'exitCode' => $exitCode];
    }

    /**
     * Собрать командную строку для нужной оболочки.
     *
     * На Windows proc_open с массивом аргументов ломает кавычки для cmd
     * («'"echo hi' is not recognized»), поэтому передаём строку целиком.
     * Для PowerShell используется -EncodedCommand: он не зависит от кавычек.
     */
    private function commandLine(string $command): string
    {
        $shell = $this->shellName;

        if ($shell === '' || $shell === 'auto') {
            $shell = DIRECTORY_SEPARATOR === '\\' ? 'cmd' : 'bash';
        }

        return match ($shell) {
            'cmd' => 'cmd /c ' . $command,
            'powershell' => 'powershell -NoProfile -NonInteractive -EncodedCommand ' . $this->encodePowerShell($command),
            'pwsh' => 'pwsh -NoProfile -NonInteractive -EncodedCommand ' . $this->encodePowerShell($command),
            'sh' => 'sh -c ' . escapeshellarg($command),
            'bash' => 'bash -c ' . escapeshellarg($command),
            default => throw new \InvalidArgumentException(
                'Неизвестная оболочка: ' . $this->shellName . '. Доступно: auto, bash, sh, cmd, powershell, pwsh.'
            ),
        };
    }

    private function encodePowerShell(string $command): string
    {
        // $ProgressPreference убирает CLIXML-мусор «Preparing modules» из stderr,
        // который PowerShell пишет при перенаправленном выводе.
        $script = '$ProgressPreference = "SilentlyContinue"; ' . $command;

        // -EncodedCommand принимает UTF-16LE в base64.
        return base64_encode((string) mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    }

    private function isAllowed(string $command): bool
    {
        if ($this->allow === []) {
            return true;
        }

        $first = $this->firstWord($command);

        foreach ($this->allow as $allowed) {
            if (strtolower($allowed) === $first) {
                return true;
            }
        }

        return false;
    }

    private function firstWord(string $command): string
    {
        $parts = preg_split('/\s+/', trim($command));

        return strtolower((string) ($parts[0] ?? ''));
    }

    /**
     * На Windows proc_terminate убивает только оболочку, дочерние процессы
     * остаются — добиваем дерево через taskkill.
     */
    private function killTree(int $pid): void
    {
        if ($pid <= 0 || DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            return;
        }

        @exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
    }

    private function format(
        string $command,
        string $stdout,
        string $stderr,
        bool $timedOut,
        bool $overflow,
        int $exitCode,
        float $timeout,
    ): string {
        $lines = ['$ ' . $command];

        $lines[] = match (true) {
            $timedOut => 'exit: таймаут ' . Timeout::format($timeout) . ' с (процесс завершён)',
            $overflow => 'exit: вывод превысил лимит, процесс завершён',
            default => 'exit: ' . $exitCode,
        };

        $stdout = $this->cut($stdout);
        $stderr = $this->cut($stderr);

        if ($stdout !== '') {
            $lines[] = "stdout:\n" . $stdout;
        }

        if ($stderr !== '') {
            $lines[] = "stderr:\n" . $stderr;
        }

        if ($stdout === '' && $stderr === '') {
            $lines[] = 'stdout: (пусто)';
        }

        return implode("\n", $lines);
    }

    private function cut(string $text): string
    {
        $text = rtrim($text);

        if (strlen($text) <= $this->maxOutput) {
            return $text;
        }

        return mb_strcut($text, 0, $this->maxOutput) . "\n… (обрезано)";
    }
}


