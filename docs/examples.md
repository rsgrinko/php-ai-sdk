# Примеры

Каждый пример начинается с заголовка «Инструменты / Флаги» — по нему видно,
что именно зарегистрировано и какие настройки включены.

| Файл | Что показывает | Инструменты | Флаги |
|---|---|---|---|
| `01-chat.php` | простой ответ | — | — |
| `02-tools.php` | цикл вызова инструментов | `get_time`, `calculator`, `get_weather` | значения по умолчанию |
| `03-custom-tool.php` | свой инструмент с нуля | `server_info` | — |
| `04-cli.php` | диалог в терминале | `get_time`, `calculator`, `get_weather` | — |
| `05-settings.php` | настройки агента | `get_time`, `calculator` | `toolChoice`, `toolApproval`, `historyLimit` |
| `06-finish.php` | явное завершение | `finish`, `calculator` | `toolChoice=required`, `finishTool` |
| `07-tools.php` | прикладные утилиты | `convert_units`, `date_calc`, `uuid`, `hash_text` | — |
| `08-shell.php` | команды оболочки | `run_command` | `workdir`, `shell`, `timeout`, `maxOutput`, `allow`, `tmpDir` |
| `09-files.php` | файлы и поиск | `read_file`, `list_dir`, `write_file`, `replace_in_file`, `search_files`, `find_files` | песочница, `create_dirs`, `all` |
| `10-http.php` | HTTP без сети | `http_request` | `allowPrivate`, `auth`, методы |
| `11-json.php` | инструменты без модели | `json_query`, `convert_units`, `date_calc`, `uuid`, `hash_text` | — |
| `12-logging.php` | логи запросов | `get_time`, `calculator` | `verbose`, `sink` |
| `13-code-task.php` | агент пишет код и проверяет тестом | `read_file`, `write_file`, `replace_in_file`, `list_dir`, `run_command` | `workdir`, `allow`, `maxRounds` |

`10-http.php` и `11-json.php` работают без модели и без сети — их можно запускать
где угодно. Остальные обращаются к модели из `.env`.



