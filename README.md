# Rate API

API курсов

## Запуск в Docker

Нужен Docker с Compose. PHP, Composer и Symfony CLI устанавливаются внутри образа.

```bash
docker compose build --pull
docker compose run --rm app composer install --no-interaction
docker compose up -d
docker compose exec app php bin/console app:rates:update
```

## Обновление курсов

```bash
docker compose exec app php bin/console rate:update
```


Файл `var/rates.json` создаётся автоматически; путь задаётся через `RATES_FILE`
относительно корня проекта. В нём сохраняются `base`, `updated_at` в UTC и словарь
`rates`. 

## Получение курсов

```bash
curl 'http://127.0.0.1:8081/api/rates'
curl 'http://127.0.0.1:8081/api/rates?base=EUR'
curl 'http://127.0.0.1:8081/api/rates?base=BTC'
```

```json
[
    {"rate": 1, "code": "EUR"},
    {"rate": 1.25, "code": "USD"}
]
```

Формула: `rate(code, base) = usd_rate(code) / usd_rate(base)`.

## Конвертация

```bash
curl 'http://127.0.0.1:8081/api/convert?amount=1&from=BTC&to=USD'
curl 'http://127.0.0.1:8081/api/convert?amount=125.50&from=EUR&to=BTC'
```

Обязательные параметры: `amount`, `from`, `to`. Поддерживаются ноль,
дробные и большие неотрицательные суммы, а также научная запись (`1e-8`).
Лимит входного числа — 256 символов, экспоненты — от -128 до 128.
Для `+` в query string используйте URL-кодирование `%2B`.

Ошибки возвращаются в JSON: `{"error":"..."}`.

- `400`: отсутствующий/неверный параметр, отрицательная сумма, неизвестная валюта.
- `503`: файл курсов отсутствует, недоступен или повреждён.
- `405`: HTTP-метод отличается от GET.

## Проверки

```bash
docker compose exec app composer check
```

Команда запускает PHPUnit, PHPStan (уровень 8), проверку DI-контейнера и YAML.
Тесты провайдеров используют MockHttpClient, тесты HTTP — отдельный файл
`var/test/rates.json`. Сеть и реальные курсы для тестов не нужны.
