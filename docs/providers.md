# Провайдеры

Меняется только `.env`:

| Провайдер | `AI_BASE_URL` | `AI_MODEL` |
|---|---|---|
| DeepSeek | `https://api.deepseek.com` | `deepseek-chat` / `deepseek-flash` |
| OpenAI | `https://api.openai.com/v1` | `gpt-4o-mini` |
| OpenRouter | `https://openrouter.ai/api/v1` | `anthropic/claude-3.5-sonnet` |
| Ollama | `http://localhost:11434/v1` | `qwen2.5:7b` |
| llama.cpp | `http://localhost:8080/v1` | `qwen3-8b` |

У Ollama и llama.cpp `AI_API_KEY` можно оставить пустым.

Не все локальные модели умеют function calling. Если инструменты не
вызываются — проверьте, что модель их поддерживает (qwen2.5/qwen3, llama 3.1+,
mistral-nemo и т.п.).


## Если не подключается

| Ошибка | Причина | Решение |
|---|---|---|
| `SSL certificate problem: unable to get local issuer certificate` | у PHP нет CA-бандла (обычно Windows) | возникает только при `AI_VERIFY_SSL=true`: укажите `AI_CAINFO=C:\path\cacert.pem` |
| `Ошибка сети: Connection refused` | сервер не запущен или другой порт | проверить `AI_BASE_URL` (`curl http://host:8080/v1/models`) |
| `HTTP 401` | пустой или неверный ключ | `AI_API_KEY` |
| `HTTP 404` / `model not found` | модель не загружена | `AI_MODEL` = имя из `/v1/models` |
| `HTTP 400 unknown field 'thinking'` | провайдер не DeepSeek | `AI_DISABLE_THINKING=false` |
| Инструменты не вызываются | модель или сборка не умеют tools | qwen3 / llama 3.1+; llama.cpp должен быть с `--jinja` |
| `HTTP 429` / `503` после паузы | лимит или перегрузка не прошли за все повторы | `AI_MAX_RETRIES` больше; если сервер просит ждать дольше 30 с (`Retry-After`), повтора нет |
| Ответ ждали в 3 раза дольше `AI_TIMEOUT` | не ошибка: таймаут не повторяется, но 5xx и обрыв связи — да | `AI_MAX_RETRIES=0`, если важна предсказуемая задержка |

Проверить сервер до всякого кода:

```bash
curl http://10.10.10.77:8080/v1/models
```


